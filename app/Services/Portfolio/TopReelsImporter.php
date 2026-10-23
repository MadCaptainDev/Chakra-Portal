<?php

namespace App\Services\Portfolio;

use App\Models\Client;
use App\Models\ContentItem;
use App\Models\PortfolioItem;
use App\Models\SocialAccount;
use App\Models\SocialMediaItem;
use App\Models\TaxonomyTerm;
use App\Services\Instagram\InstagramGraph;
use App\Support\PublicUpload;
use App\Support\ContentDashboard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Adds a client's best-performing Instagram reels to the portfolio: the
 * top ones by views that are not in it yet, each mapped to its post (so its
 * numbers keep refreshing with every instagram:sync) with its cover saved.
 * The same steps PortfolioItemController takes for a hand-picked post.
 */
class TopReelsImporter
{
    /** @return list<PortfolioItem> the pieces added */
    public function add(Client $client, int $count = 2): array
    {
        $account = $client->socialAccounts()->forPlatform(SocialAccount::PLATFORM_INSTAGRAM)->connected()->first();
        if (! $account) {
            return [];
        }

        $taken = PortfolioItem::query()->whereNotNull('social_media_item_id')->pluck('social_media_item_id')->flip();

        $picks = $account->socialMediaItems()->with('insights')->get()
            ->filter(fn (SocialMediaItem $m) => $m->isReel() && $m->metricValue('views'))
            ->sortByDesc(fn (SocialMediaItem $m) => $m->metricValue('views'))
            // The client's top N, whichever of them are not in yet -- running it
            // again adds nothing new unless a newer reel climbs into the top N.
            ->take($count)
            ->reject(fn (SocialMediaItem $m) => $taken->has($m->id));

        return $picks->map(fn (SocialMediaItem $media) => $this->addMedia($client, $media))->values()->all();
    }

    /**
     * Recent reels that did well, for the top of the portfolio: posted in
     * the last $days days, at least $minViews views, confirmed posted (see
     * postedCheck()), at most $perClient per client so one busy account
     * cannot fill the row. Best first. Nothing is added here.
     *
     * @return Collection<int, array{media: SocialMediaItem, client: Client, views: int}>
     */
    public function recentCandidates(int $days = 30, int $minViews = 20_000, int $perClient = 2): Collection
    {
        $taken = PortfolioItem::query()->whereNotNull('social_media_item_id')->pluck('social_media_item_id')->flip();

        return SocialMediaItem::query()->with(['insights', 'socialAccount.client'])
            ->where('posted_at', '>=', now()->subDays($days)->startOfDay())
            ->get()
            ->filter(fn (SocialMediaItem $m) => $m->isReel() && $m->socialAccount?->client && ! $taken->has($m->id))
            ->map(fn (SocialMediaItem $m) => ['media' => $m, 'client' => $m->socialAccount->client, 'views' => (int) $m->metricValue('views')])
            ->filter(fn ($c) => $c['views'] >= $minViews && self::postedCheck($c['media'])['ok'])
            ->sortByDesc('views')
            ->groupBy(fn ($c) => $c['client']->id)
            ->flatMap(fn ($forClient) => $forClient->take(max(0, $perClient - $this->recentCount($forClient->first()['client'], $days))))
            ->sortByDesc('views')
            ->values();
    }

    /** @return list<PortfolioItem> */
    public function addRecent(int $days = 30, int $minViews = 20_000, int $perClient = 2, int $limit = 10): array
    {
        return $this->recentCandidates($days, $minViews, $perClient)->take($limit)
            ->map(fn ($c) => $this->addMedia($c['client'], $c['media']))->values()->all();
    }

    /**
     * Whether a reel really went out as planned: Instagram has the post, and
     * the Chakra Planner row it is linked to says Published with a publishing
     * date within a day of when Instagram says it was posted.
     *
     * @return array{ok: bool, reason: string, planner_date: ?string, posted_on: ?string}
     */
    public static function postedCheck(SocialMediaItem $media): array
    {
        $posted = $media->posted_at?->copy()->timezone(config('app.timezone'));
        $row = ContentItem::query()->where('social_media_item_id', $media->id)->first(['id', 'status', 'published_date']);
        $plannerDate = $row?->published_date ? Carbon::parse($row->published_date)->toDateString() : null;
        $out = fn (bool $ok, string $reason) => ['ok' => $ok, 'reason' => $reason, 'planner_date' => $plannerDate, 'posted_on' => $posted?->toDateString()];

        if (! $posted) {
            return $out(false, 'Instagram has no posting date for it.');
        }
        if (! $row) {
            return $out(false, 'Not linked to a Chakra Planner row.');
        }
        if (! in_array($row->status, ContentDashboard::STATUS_GROUPS['published'], true)) {
            return $out(false, "The planner says \"{$row->status}\", not Published.");
        }
        if (! $plannerDate || abs(Carbon::parse($plannerDate)->diffInDays($posted->copy()->startOfDay(), false)) > 1) {
            return $out(false, 'The planner date ('.($plannerDate ?? 'none').') does not match the Instagram posting date ('.$posted->toDateString().').');
        }

        return $out(true, 'Posted '.$posted->format('j M Y').', as the planner says.');
    }

    /** How many of this client's pieces are already from the last $days days. */
    private function recentCount(Client $client, int $days): int
    {
        return PortfolioItem::query()->where('client_id', $client->id)->whereNotNull('social_media_item_id')
            ->whereDate('published_on', '>=', now()->subDays($days))->count();
    }

    /**
     * One reel into the portfolio (or the piece already made from it): the
     * client's usual category, 9:16, its cover saved, mapped to its post.
     */
    public function addMedia(Client $client, SocialMediaItem $media): PortfolioItem
    {
        if ($existing = PortfolioItem::where('social_media_item_id', $media->id)->first()) {
            return $existing;
        }

        $account = $media->socialAccount;
        $item = PortfolioItem::create([
            'client_id' => $client->id,
            'portfolio_category_id' => PortfolioItem::query()->where('client_id', $client->id)->whereNotNull('portfolio_category_id')->value('portfolio_category_id'),
            'format_id' => TaxonomyTerm::where('type', TaxonomyTerm::TYPE_FORMAT)->where('name', '9:16 vertical')->value('id'),
            'title' => self::title($media, $client),
            'thumbnail_path' => $this->cover($media, $account),
            'is_visible' => true,
            'sort_order' => 0,
        ]);
        $item->mapToInstagram($media);
        $item->refreshFromInstagram($media);

        return $item;
    }

    /** Pieces from this account still without a cover get one (after a sync renews the links). */
    public function fillMissingCovers(SocialAccount $account): int
    {
        $filled = 0;
        PortfolioItem::query()->whereNull('thumbnail_path')
            ->whereIn('social_media_item_id', $account->socialMediaItems()->select('id'))
            ->with('socialMediaItem')->get()
            ->each(function (PortfolioItem $item) use ($account, &$filled) {
                if ($path = $this->cover($item->socialMediaItem, $account)) {
                    $item->forceFill(['thumbnail_path' => $path])->save();
                    $filled++;
                }
            });

        return $filled;
    }

    /**
     * The reel's cover, saved locally. Instagram's CDN links expire after a
     * few days, so an older cached one is asked for again before giving up.
     */
    private function cover(SocialMediaItem $media, SocialAccount $account): ?string
    {
        $saved = ($url = $media->thumbnail_url ?: $media->media_url) ? PublicUpload::storeFromUrl($url, 'portfolio/thumbnails') : null;
        if ($saved || ! $account->access_token) {
            return $saved;
        }

        try {
            $fresh = app(InstagramGraph::class)->get($media->platform_media_id, $account->access_token, ['fields' => 'thumbnail_url,media_url']);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
        $media->forceFill(array_filter(['thumbnail_url' => $fresh['thumbnail_url'] ?? null, 'media_url' => $fresh['media_url'] ?? null]))->save();

        return ($url = $fresh['thumbnail_url'] ?? $fresh['media_url'] ?? null) ? PublicUpload::storeFromUrl($url, 'portfolio/thumbnails') : null;
    }

    /** The caption's first line, without hashtags and mentions, as a short title. */
    public static function title(SocialMediaItem $media, Client $client): string
    {
        $line = Str::of((string) $media->caption)->explode("\n")->map(fn ($l) => trim($l))->first(fn ($l) => $l !== '' && ! str_starts_with($l, '#')) ?? '';
        // Unicode-aware: trim() works on bytes and would cut an emoji in half.
        $line = preg_replace(['/[#@][\p{L}\p{N}_.]+/u', '/\s{2,}/u', '/^[\s\-–—|·:]+|[\s\-–—|·:]+$/u'], ['', ' ', ''], $line);

        return $line !== '' ? Str::limit($line, 70, '…') : ($client->publicName() ?: $client->name).' reel';
    }
}
