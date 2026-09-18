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
}
