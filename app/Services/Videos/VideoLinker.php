<?php

namespace App\Services\Videos;

use App\Models\ContentAccountVenture;
use App\Models\ContentItem;
use App\Models\Script;
use App\Models\Shoot;
use App\Models\ShootVideo;
use App\Models\SocialMediaItem;
use App\Models\Todo;
use App\Models\Video;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Keeps one Video per Notion content item and fills in what it is linked to.
 *
 * Additive: a link that is found is set, one that is not found is left as it
 * was, so a link made by hand survives and a Notion hiccup unlinks nothing.
 * Nothing here writes to the content item or to Notion.
 */
class VideoLinker
{
    /**
     * @return array{videos: int, created: int, updated: int}
     */
    public function run(): array
    {
        $this->linkScriptsByTitle();
        $this->markScriptsShot();

        $ventureToClient = ContentAccountVenture::query()
            ->with('contentAccount')
            ->get()
            ->mapWithKeys(fn (ContentAccountVenture $row) => [$row->venture => $row->contentAccount?->client_id])
            ->filter();

        $scripts = Script::query()->whereNotNull('content_item_id')->orderBy('id')
            ->get(['id', 'content_item_id', 'client_id'])->unique('content_item_id')->keyBy('content_item_id');

        $shoots = Shoot::query()->whereNotNull('notion_shoot_id')->pluck('id', 'notion_shoot_id');

        $footage = ShootVideo::query()->whereNotNull('script_id')->orderBy('id')
            ->get(['id', 'script_id'])->unique('script_id')->keyBy('script_id');

        $todos = Todo::query()->whereNotNull('content_item_id')->whereNull('parent_id')->orderBy('id')
            ->get(['id', 'content_item_id', 'kind', 'posted_at'])->groupBy('content_item_id')
            ->map(fn ($rows) => $rows->firstWhere(fn (Todo $t) => $t->posted_at !== null) ?? $rows->first());

        $media = SocialMediaItem::query()->get(['id', 'media_product_type'])->keyBy('id');

        $created = $updated = $total = 0;

        ContentItem::query()->visible()->with('video')->chunkById(300, function ($items) use (
            $ventureToClient, $scripts, $shoots, $footage, $todos, $media, &$created, &$updated, &$total,
        ) {
            foreach ($items as $item) {
                $total++;
                $video = $item->video ?? new Video(['content_item_id' => $item->id]);
                $script = $scripts->get($item->id);
                $todo = $todos->get($item->id);
                $mediaItem = $item->social_media_item_id ? $media->get($item->social_media_item_id) : null;

                $found = [
                    'client_id' => $ventureToClient[$item->venture] ?? $script?->client_id,
                    'script_id' => $script?->id,
                    'shoot_id' => $item->notion_shoot_id ? ($shoots[$item->notion_shoot_id] ?? null) : null,
                    'shoot_video_id' => $script ? $footage->get($script->id)?->id : null,
                    'todo_id' => $todo?->id,
                    'social_media_item_id' => $item->social_media_item_id,
                    'posted_as' => $this->postedAs($todo, $mediaItem),
                ];

                $video->fill(array_filter($found, fn ($value) => $value !== null));

                if (! $video->exists) {
                    $video->save();
                    $created++;
                } elseif ($video->isDirty()) {
                    $video->save();
                    $updated++;
                }
            }
        });

        $this->pairShorts();

        return ['videos' => $total, 'created' => $created, 'updated' => $updated];
    }

    /**
     * Notion statuses that only come after the shoot. A script whose reel has
     * reached one of these has been shot, whatever the script itself says.
     */
    public const PAST_SHOOT = ['To Be Edited', 'Edit in Progress', 'Under Review', 'Video Ready', 'Delivered', 'Published'];

    /** A script written in the portal, not yet tied to its row in the Notion Reel Planner. */
    private function linkScriptsByTitle(): int
    {
        $scripts = Script::query()->whereNull('content_item_id')
            ->where('status', '!=', Script::STATUS_ARCHIVED)->get(['id', 'title', 'client_id']);

        if ($scripts->isEmpty()) {
            return 0;
        }

        $ventureToClient = $this->ventureToClient();
        $claimed = Script::query()->whereNotNull('content_item_id')->pluck('content_item_id')->flip();
        $reels = ContentItem::query()->visible()->where('source', ContentItem::SOURCE_REEL)
            ->get(['id', 'title', 'venture'])
            ->reject(fn (ContentItem $item) => $claimed->has($item->id))
            ->groupBy(fn (ContentItem $item) => $this->norm($item->title));

        $linked = 0;

        foreach ($scripts as $script) {
            $candidates = ($reels->get($this->norm($script->title)) ?? collect())
                ->filter(fn (ContentItem $item) => $script->client_id === null
                    || ($ventureToClient[$item->venture] ?? $script->client_id) === $script->client_id);

            // Only an unambiguous title is a link; two reels with one title are a guess.
            if ($candidates->count() === 1) {
                Script::whereKey($script->id)->update(['content_item_id' => $candidates->first()->id]);
                $reels->forget($this->norm($script->title));
                $linked++;
            }
        }

        return $linked + $this->linkScriptsByLikeness($ventureToClient);
    }

    /**
     * The titles rarely match exactly: Notion's carry a "Revanth - " prefix,
     * and Tamil words are spelled more than one way ("Naattu Katu Podalama"
     * in Notion, "Nattu Kattu Podalaamaa?" in the script). So, for a script
     * still unlinked, its own client's unclaimed reels are compared with
     * doubled letters collapsed and the client prefix dropped; one title
     * containing the other counts too ("Veetla Slipper" against "Veetla
     * Slipper – Aadambaram Illa"). Only a clear winner links: at least 75%
     * alike and 12 points ahead of the next reel. Never across clients.
     */
    private function linkScriptsByLikeness(Collection $ventureToClient): int
    {
        $scripts = Script::query()->whereNull('content_item_id')->whereNotNull('client_id')
            ->where('status', '!=', Script::STATUS_ARCHIVED)->get(['id', 'title', 'client_id']);
        if ($scripts->isEmpty()) {
            return 0;
        }

        $claimed = Script::query()->whereNotNull('content_item_id')->pluck('content_item_id')->flip();
        $reels = ContentItem::query()->visible()->where('source', ContentItem::SOURCE_REEL)
            ->where('created_at', '>=', now()->subMonths(6))->get(['id', 'title', 'venture'])
            ->reject(fn (ContentItem $item) => $claimed->has($item->id))
            ->groupBy(fn (ContentItem $item) => $ventureToClient[$item->venture] ?? 0);

        $linked = 0;
        foreach ($scripts as $script) {
            $mine = ($reels->get($script->client_id) ?? collect())
                ->map(fn (ContentItem $item) => ['item' => $item, 'score' => $this->likeness($this->core($item->title), $this->norm($script->title))])
                ->sortByDesc('score')->values();
            $best = $mine->first();
            if (! $best || $best['score'] < 75 || $best['score'] - ($mine->get(1)['score'] ?? 0) < 12) {
                continue;
            }

            Script::whereKey($script->id)->update(['content_item_id' => $best['item']->id]);
            $reels->put($script->client_id, $reels->get($script->client_id)->reject(fn ($i) => $i->id === $best['item']->id));
            $linked++;
        }

        return $linked;
    }

    /** How alike two titles are, 0-100, forgiving transliteration and a short title inside a long one. */
    private function likeness(string $a, string $b): float
    {
        $skel = fn (string $t) => (string) preg_replace('/(.)\1+/u', '$1', str_replace(' ', '', $t));
        [$x, $y] = [$skel($a), $skel($b)];
        if ($x === '' || $y === '') {
            return 0;
        }
        [$short, $long] = mb_strlen($x) <= mb_strlen($y) ? [$x, $y] : [$y, $x];
        if (mb_strlen($short) >= 8 && str_contains($long, $short)) {
            return 90;
        }
        similar_text($x, $y, $percent);

        return $percent;
    }

    /**
     * The Reel Planner is where the shoot is tracked, so it decides whether a
     * script has been shot: marks it recorded (Completed, with the shot day; one already set stays).
     */
    private function markScriptsShot(): int
    {
        $scripts = Script::query()->whereNotNull('content_item_id')->whereNull('shot_at')
            ->get(['id', 'content_item_id']);

        if ($scripts->isEmpty()) {
            return 0;
        }

        $items = ContentItem::query()->visible()->whereIn('id', $scripts->pluck('content_item_id'))
            ->whereIn('status', self::PAST_SHOOT)->with('notionShoot:id,shoot_date')
            ->get(['id', 'shoot_date', 'notion_shoot_id', 'published_date'])->keyBy('id');

        $marked = 0;

        foreach ($scripts as $script) {
            $item = $items->get($script->content_item_id);
            $when = $item?->shoot_date ?? $item?->notionShoot?->shoot_date ?? $item?->published_date;

            if ($when !== null) {
                Script::find($script->id)?->markRecorded(Carbon::parse($when)->startOfDay());
                $marked++;
            }
        }

        return $marked;
    }

    /**
     * Each Shorts-planner row is the reel it shares a title with. Shorts are
     * only ever re-uploaded reels, so the reel is the thing being paired to.
     * A pairing already made is kept.
     */
    private function pairShorts(): int
    {
        $claimed = Video::query()->whereNotNull('short_content_item_id')->pluck('short_content_item_id')->flip();
        $shorts = ContentItem::query()->visible()->where('source', ContentItem::SOURCE_YOUTUBE)
            ->get(['id', 'title', 'venture', 'published_date'])
            ->reject(fn (ContentItem $item) => $claimed->has($item->id));

        if ($shorts->isEmpty()) {
            return 0;
        }

        $reelsByVenture = ContentItem::query()->visible()->where('source', ContentItem::SOURCE_REEL)
            ->get(['id', 'title', 'venture', 'published_date'])->groupBy('venture');

        $taken = Video::query()->whereNotNull('short_content_item_id')->pluck('id')->flip();
        $paired = 0;

        foreach ($shorts as $short) {
            $reel = $this->reelFor($short, $reelsByVenture->get($short->venture, collect()));

            if ($reel === null) {
                continue;
            }

            $video = Video::query()->where('content_item_id', $reel->id)->whereNull('short_content_item_id')->first();

            if ($video !== null && ! $taken->has($video->id)) {
                $video->forceFill(['short_content_item_id' => $short->id])->save();
                $taken[$video->id] = true;
                $paired++;
            }
        }

        return $paired;
    }

    /**
     * @param  Collection<int, ContentItem>  $reels  the same client's reels
     */
    private function reelFor(ContentItem $short, Collection $reels): ?ContentItem
    {
        $title = $this->norm($short->title);
        $exact = $reels->filter(fn (ContentItem $r) => $this->norm($r->title) === $title);

        if ($exact->isEmpty()) {
            // The client's name is often typed differently in front of the same title.
            $core = $this->core($short->title);
            $exact = strlen($core) >= 8
                ? $reels->filter(fn (ContentItem $r) => $this->core($r->title) === $core)
                : collect();
        }

        if ($exact->isEmpty()) {
            return $this->fuzzy($short, $reels);
        }

        return $exact->count() === 1 ? $exact->first() : $this->nearestInTime($short, $exact);
    }

    /** One clearly closest title, or nothing; a near-miss pairing is worse than none. */
    private function fuzzy(ContentItem $short, Collection $reels): ?ContentItem
    {
        $core = $this->core($short->title);

        if (strlen($core) < 12) {
            return null;
        }

        $scored = $reels->map(function (ContentItem $r) use ($core) {
            similar_text($core, $this->core($r->title), $percent);

            return ['reel' => $r, 'score' => $percent];
        })->sortByDesc('score')->values();

        $best = $scored->get(0);
        $next = $scored->get(1);

        return $best && $best['score'] >= 90 && (! $next || $best['score'] - $next['score'] >= 5)
            ? $best['reel']
            : null;
    }

    /** Repeated titles: the reel published closest to the Short, within about six weeks. */
    private function nearestInTime(ContentItem $short, Collection $reels): ?ContentItem
    {
        if ($short->published_date === null) {
            return null;
        }

        $nearest = $reels->filter(fn (ContentItem $r) => $r->published_date !== null)
            ->sortBy(fn (ContentItem $r) => abs($r->published_date->diffInDays($short->published_date)))
            ->first();

        return $nearest && abs($nearest->published_date->diffInDays($short->published_date)) <= 45 ? $nearest : null;
    }

    private function norm(?string $title): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower((string) $title))));
    }

    /** The title without a leading "Client - ". */
    private function core(?string $title): string
    {
        return $this->norm(preg_replace('/^[^-–—:]{2,32}\s[-–—:]\s*/u', '', (string) $title));
    }

    /** @return Collection<string, int> */
    private function ventureToClient(): Collection
    {
        return ContentAccountVenture::query()->with('contentAccount')->get()
            ->mapWithKeys(fn (ContentAccountVenture $row) => [$row->venture => $row->contentAccount?->client_id])
            ->filter();
    }

    /**
     * What the video really went out as. The editor's own post is the first
     * word; failing that, the Instagram post it was matched to.
     */
    private function postedAs(?Todo $todo, ?SocialMediaItem $media): ?string
    {
        if ($todo && $todo->posted_at !== null && array_key_exists($todo->kind, Todo::KINDS)) {
            return $todo->kind;
        }

        if ($media === null) {
            return null;
        }

        return match (true) {
            $media->isReel() => 'reel',
            $media->media_product_type === 'STORY' => 'story',
            default => 'post',
        };
    }
}
