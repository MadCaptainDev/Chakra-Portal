<?php

namespace App\Services\Portfolio;

use App\Models\Client;
use App\Models\PortfolioItem;
use App\Models\SocialAccount;
use App\Models\SocialMediaItem;
use App\Models\TaxonomyTerm;
use App\Services\Instagram\InstagramGraph;
use App\Support\PublicUpload;
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
