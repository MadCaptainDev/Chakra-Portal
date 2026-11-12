<?php

namespace App\Support;

use App\Models\ContentItem;
use Illuminate\Support\Collection;

/**
 * One row per piece of work, carrying every place it goes.
 *
 * Notion keeps a database per destination, so the same cut is planned once
 * per place it is shared -- a reel row, a YouTube row, often a feed post row
 * too. They are one piece of work. Counting the rows instead told every
 * client a number about a third too high: 1,919 published rows across the
 * studio are 1,271 actual pieces (376 shared as post+reel, 148 as
 * reel+YouTube, 62 as all three).
 *
 * Title + published date is the key, because that is exactly what the studio
 * duplicates: the same cut, planned for the same day, once per destination.
 * Two genuinely different pieces sharing a title and a date would merge,
 * which has not happened in 1,919 rows and would be a planner mistake worth
 * seeing anyway.
 *
 * Callers group by status themselves and collapse each bucket separately --
 * see ContentCalendarController. That is deliberate: a reel that is out while
 * its YouTube cut is still scheduled is two different facts about two
 * different days, and merging them would report the later one as already
 * delivered.
 */
class ContentPieces
{
    /**
     * Destination order, so every row reads the same way regardless of the
     * order Notion happened to be synced in. Plural labels count things.
     */
    public const TYPES = [
        ContentItem::SOURCE_REEL => 'Reels',
        ContentItem::SOURCE_POST => 'Posts',
        ContentItem::SOURCE_STORY => 'Stories',
        ContentItem::SOURCE_YOUTUBE => 'YouTube',
    ];

    /**
     * Which platform a source actually is -- Reels, Posts and Stories are
     * Instagram, only Shorts/videos are YouTube. Same mapping as
     * content-dashboard/index.blade.php's $platformIcon, so the client-facing
     * pages and the staff board put the same brand mark on the same source
     * rather than each guessing at it separately.
     */
    public const PLATFORMS = [
        ContentItem::SOURCE_REEL => 'instagram',
        ContentItem::SOURCE_POST => 'instagram',
        ContentItem::SOURCE_STORY => 'instagram',
        ContentItem::SOURCE_YOUTUBE => 'youtube',
    ];

    /**
     * @param  Collection<int, ContentItem>  $items
     * @return Collection<int, array{title: string, date: ?\Illuminate\Support\Carbon, channels: array<string, array{label: string, icon: string, url: ?string}>, note: ?string, items: Collection<int, ContentItem>}>
     */
    public static function collapse(Collection $items): Collection
    {
        return $items
            ->groupBy(fn (ContentItem $item) => mb_strtolower(trim((string) $item->title))
                // An undated item -- anything still being made -- keys on its
                // title alone rather than collapsing every dateless row together.
                .'|'.($item->published_date?->toDateString() ?? ''))
            ->map(function (Collection $rows) {
                $channels = collect(self::TYPES)
                    ->mapWithKeys(function (string $label, string $source) use ($rows) {
                        $row = $rows->firstWhere('source', $source);

                        return $row ? [$source => self::channel($row)] : [];
                    })
                    // A source outside self::TYPES still has to appear, or the
                    // piece would claim it went nowhere at all.
                    ->union($rows
                        ->reject(fn (ContentItem $row) => isset(self::TYPES[$row->source]))
                        ->mapWithKeys(fn (ContentItem $row) => [$row->source => self::channel($row)])
                        ->all())
                    ->all();

                $first = $rows->first();

                return [
                    // An untitled piece still has to say what it is.
                    'title' => trim((string) $first->title) ?: $first->sourceLabel(),
                    'date' => $first->published_date,
                    'channels' => $channels,
                    // Only when it adds something: post_type is usually either
                    // null or a second spelling of the source label.
                    'note' => $rows
                        ->pluck('post_type')
                        ->filter()
                        ->first(fn (string $note) => ! in_array($note, array_column($channels, 'label'), true)),
                    'items' => $rows,
                ];
            })
            ->values();
    }

    /**
     * How many pieces went to each destination.
     *
     * These add up to more than the piece count, and should: a cut shared as
     * a reel and on YouTube is one piece and belongs under both.
     *
     * @param  Collection<int, array{channels: array<string, mixed>}>  $pieces
     * @return Collection<string, int>
     */
    public static function countsByChannel(Collection $pieces): Collection
    {
        return collect(self::TYPES)
            ->map(fn (string $label, string $source) => $pieces
                ->filter(fn (array $piece) => isset($piece['channels'][$source]))
                ->count());
    }

    /**
     * @return array{label: string, icon: string, platform: string, url: ?string}
     */
    private static function channel(ContentItem $row): array
    {
        return [
            'label' => $row->sourceLabel(),
            // Kept for anywhere still reading it directly; the client-facing
            // views render 'platform' through <x-brand-icon> instead -- the
            // same real Instagram/YouTube marks the admin dashboard uses,
            // not this emoji.
            'icon' => $row->sourceIcon(),
            'platform' => self::PLATFORMS[$row->source] ?? 'instagram',
            'url' => $row->notion_url,
        ];
    }

    /**
     * The video a planner row belongs to, whatever planner it sits in: the
     * Reel and YouTube rows of one cut are one video, so staff counts ("in
     * hand", "out this month", an editor's output) count it once.
     *
     * Unlike collapse() this ignores the date -- the YouTube copy is often
     * scheduled a day after the reel -- and the "Client - " in front of the
     * title, which is typed differently between planners. Within one venture.
     */
    public static function videoKey(ContentItem $item): string
    {
        $core = self::core($item->title);

        return self::family($item->venture).'|'.(mb_strlen($core) >= 4 ? $core : self::norm($item->title));
    }

    /**
     * Which account a venture spelling belongs to. The planners spell one
     * client differently ("Janet" in the Reel Planner, "janet Hospital" in
     * YouTube; "Thillai Pets Clinic" and "Thillai pets"), and the content
     * account connections already say they are one. Then the timesheet's
     * client, then the spelling itself.
     */
    public static function family(?string $venture): string
    {
        $fold = mb_strtolower(trim((string) $venture));
        $accounts = self::accountsByVenture();
        if (isset($accounts[$fold])) {
            return 'a:'.$accounts[$fold];
        }
        $client = TimesheetVenture::clientIdFor($venture);

        return $client ? 'c:'.$client : 'v:'.$fold;
    }

    /**
     * Every venture spelling connected to the same accounts as these.
     *
     * @param  list<string>  $ventures
     * @return list<string>
     */
    public static function siblings(array $ventures): array
    {
        $families = collect($ventures)->map(fn ($v) => self::family($v))->unique();
        $more = \App\Models\ContentAccountVenture::query()->pluck('venture')
            ->filter(fn ($v) => $families->contains(self::family($v)));

        return collect($ventures)->merge($more)->unique(fn ($v) => mb_strtolower(trim((string) $v)))->values()->all();
    }

    /** @return array<string, int> folded venture => content account id, once per request */
    private static function accountsByVenture(): array
    {
        $key = 'content-pieces.accounts-by-venture';
        if (! app()->bound($key)) {
            app()->instance($key, \App\Models\ContentAccountVenture::query()->orderBy('id')->get(['venture', 'content_account_id'])
                ->mapWithKeys(fn ($r) => [mb_strtolower(trim((string) $r->venture)) => (int) $r->content_account_id])->all());
        }

        return app($key);
    }

    /**
     * Planner rows grouped into videos, each group keyed by videoKey().
     *
     * @param  Collection<int, ContentItem>  $items
     * @return Collection<string, Collection<int, ContentItem>>
     */
    public static function videos(Collection $items): Collection
    {
        return $items->groupBy(fn (ContentItem $item) => self::videoKey($item));
    }

    /** Lower case, letters and digits only, single spaces. */
    public static function norm(?string $title): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower((string) $title))));
    }

    /** The title without a leading "Client - ". */
    public static function core(?string $title): string
    {
        return self::norm(preg_replace('/^[^-–—:]{2,32}\s[-–—:]\s*/u', '', (string) $title));
    }

    /** The title as people read it: without the "Client - " in front. */
    public static function displayTitle(?string $title): string
    {
        $t = trim((string) preg_replace('/^[^-–—:]{2,32}\s[-–—:]\s*/u', '', (string) $title));

        return $t !== '' ? $t : trim((string) $title);
    }
}
