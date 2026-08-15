<?php

namespace App\Support;

use App\Models\ContentItem;
use App\Models\TimesheetEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the editors produced, against the hours it took.
 *
 * Two records that were never designed to meet: content_items comes from the
 * Notion planners and knows what was published and how hard it was; timesheet
 * entries know how long people worked. Neither can answer "how much can an
 * editor edit" alone.
 *
 * They are joined on a person's name, because that is the only thing they
 * share -- Notion has no idea the portal exists. Names are matched on their
 * first word, case-insensitively, since Notion carries "Sanjai" where the
 * portal carries "Sanjai Kumar". It is a loose join and the screen says so.
 *
 * Everything is grouped in PHP rather than by the database. The grouping is by
 * month, and every SQL dialect spells that differently -- DATE_FORMAT is MySQL
 * only, and this app's tests run on SQLite. A thousand rows cost nothing to
 * group in memory; a screen that works in production and not in the tests costs
 * a great deal.
 */
class EditorThroughput
{
    public const TIER_LOW = 'low';

    public const TIER_MEDIUM = 'medium';

    public const TIER_HIGH = 'high';

    public const TIER_NONE = 'none';

    /** @var array<string, string> */
    public const TIER_LABELS = [
        self::TIER_HIGH => 'High effort',
        self::TIER_MEDIUM => 'Medium effort',
        self::TIER_LOW => 'Low effort',
        self::TIER_NONE => 'Untiered',
    ];

    /**
     * Read Notion's tier text, typos included.
     *
     * The Reel planner says "Hight Effort & Work" -- a typo that has been there
     * long enough to be load-bearing. Matched on the prefix rather than
     * corrected, because this reads Notion and does not get to rename things in
     * it. If somebody fixes the spelling there, "High" is matched too and
     * nothing here needs touching.
     */
    public static function tierOf(?string $raw): string
    {
        $value = mb_strtolower(trim((string) $raw));

        return match (true) {
            $value === '' => self::TIER_NONE,
            str_starts_with($value, 'hight'), str_starts_with($value, 'high') => self::TIER_HIGH,
            str_starts_with($value, 'medium'), str_starts_with($value, 'med') => self::TIER_MEDIUM,
            str_starts_with($value, 'low') => self::TIER_LOW,
            default => self::TIER_NONE,
        };
    }

    /**
     * The whole picture for one window.
     *
     * @return array<string, mixed>
     */
    public static function between(Carbon $from, Carbon $to): array
    {
        $items = ContentItem::query()
            ->whereNotNull('published_date')
            ->whereDate('published_date', '>=', $from->toDateString())
            ->whereDate('published_date', '<=', $to->toDateString())
            ->get(['editor', 'tier', 'published_date', 'source', 'venture']);

        $hours = TimesheetEntry::with('user')
            ->where('task_type', TimesheetEntry::TASK_EDITING)
            ->counted()
            ->where('worked_on', '>=', $from->toDateString())
            ->where('worked_on', '<', $to->copy()->addDay()->toDateString())
            ->get(['user_id', 'worked_on', 'minutes']);

        $people = self::editors($items, $hours);

        return [
            'from' => $from,
            'to' => $to,
            'rows' => self::rows($people, $items, $hours),
            'months' => self::months($people, $items, $hours, $from, $to),
            'shared' => $items->filter(fn (ContentItem $i) => self::isShared($i->editor))->count(),
            'unattributedItems' => $items->filter(fn (ContentItem $i) => blank($i->editor))->count(),
            'totalItems' => $items->count(),
            'lastSynced' => ContentItem::max('synced_at'),
            'sources' => $items->pluck('source')->unique()->filter()->sort()->values()->all(),
        ];
    }

    /**
     * Everyone who either published something or logged editing time.
     *
     * Both halves matter: somebody with output and no hours is a name Notion
     * knows and the portal does not, and somebody with hours and no output is
     * the more interesting case -- either their planner is not synced, or the
     * work is not reaching the board.
     *
     * @param  Collection<int, ContentItem>  $items
     * @param  Collection<int, TimesheetEntry>  $hours
     * @return Collection<string, array{user: ?User, label: string}>
     */
    private static function editors(Collection $items, Collection $hours): Collection
    {
        $people = collect();

        foreach ($hours as $entry) {
            if (! $entry->user) {
                continue;
            }

            $people->put(self::key($entry->user->name), ['user' => $entry->user, 'label' => $entry->user->name]);
        }

        foreach ($items as $item) {
            if (blank($item->editor) || self::isShared($item->editor)) {
                continue;
            }

            $key = self::key($item->editor);

            if (! $people->has($key)) {
                $people->put($key, ['user' => null, 'label' => trim($item->editor)]);
            }
        }

        return $people;
    }

    /**
     * One row per editor: what they published, split by tier, over the hours
     * they logged.
     *
     * @param  Collection<string, array{user: ?User, label: string}>  $people
     * @param  Collection<int, ContentItem>  $items
     * @param  Collection<int, TimesheetEntry>  $hours
     * @return Collection<int, array<string, mixed>>
     */
    private static function rows(Collection $people, Collection $items, Collection $hours): Collection
    {
        return $people
            ->map(function (array $person, string $key) use ($items, $hours) {
                $theirs = $items->filter(
                    fn (ContentItem $i) => ! blank($i->editor)
                        && ! self::isShared($i->editor)
                        && self::key($i->editor) === $key
                );

                $theirHours = $person['user']
                    ? $hours->where('user_id', $person['user']->id)
                    : collect();

                $minutes = (int) $theirHours->sum('minutes');
                $days = $theirHours->pluck('worked_on')->map->toDateString()->unique()->count();
                $tiers = self::tierCounts($theirs);

                return [
                    'key' => $key,
                    'label' => $person['label'],
                    'user' => $person['user'],
                    'items' => $theirs->count(),
                    'tiers' => $tiers,
                    'minutes' => $minutes,
                    'days' => $days,
                    // Null rather than zero when either half is missing: "no
                    // data" and "instant" must not render the same.
                    'minutesPerItem' => $theirs->count() > 0 && $minutes > 0
                        ? (int) round($minutes / $theirs->count())
                        : null,
                    'itemsPerDay' => $days > 0 ? round($theirs->count() / $days, 1) : null,
                    'hoursPerDay' => $days > 0 ? round($minutes / 60 / $days, 1) : null,
                    // The share of output that was hard, which is what makes
                    // two editors' rates comparable or not.
                    'hardShare' => $theirs->count() > 0
                        ? (int) round(($tiers[self::TIER_HIGH] + $tiers[self::TIER_MEDIUM]) / $theirs->count() * 100)
                        : null,
                ];
            })
            ->sortByDesc('items')
            ->values();
    }

    /**
     * Month by month, per editor. The trend is the part that tells you
     * something a single average cannot.
     *
     * @param  Collection<string, array{user: ?User, label: string}>  $people
     * @param  Collection<int, ContentItem>  $items
     * @param  Collection<int, TimesheetEntry>  $hours
     * @return Collection<int, array<string, mixed>>
     */
    private static function months(Collection $people, Collection $items, Collection $hours, Carbon $from, Carbon $to): Collection
    {
        $months = collect();
        $cursor = $from->copy()->startOfMonth();

        while ($cursor->lte($to)) {
            $key = $cursor->format('Y-m');

            $monthItems = $items->filter(fn (ContentItem $i) => $i->published_date?->format('Y-m') === $key);
            $monthHours = $hours->filter(fn (TimesheetEntry $e) => $e->worked_on->format('Y-m') === $key);

            $perEditor = $people->map(function (array $person, string $pkey) use ($monthItems, $monthHours) {
                $theirs = $monthItems->filter(
                    fn (ContentItem $i) => ! blank($i->editor)
                        && ! self::isShared($i->editor)
                        && self::key($i->editor) === $pkey
                );

                $minutes = $person['user']
                    ? (int) $monthHours->where('user_id', $person['user']->id)->sum('minutes')
                    : 0;

                return [
                    'label' => $person['label'],
                    'items' => $theirs->count(),
                    'minutes' => $minutes,
                    'tiers' => self::tierCounts($theirs),
                    'minutesPerItem' => $theirs->count() > 0 && $minutes > 0
                        ? (int) round($minutes / $theirs->count())
                        : null,
                ];
            })->filter(fn (array $row) => $row['items'] > 0 || $row['minutes'] > 0)->values();

            $months->push([
                'key' => $key,
                'label' => $cursor->format('M Y'),
                'short' => $cursor->format('M'),
                'items' => $monthItems->count(),
                'minutes' => (int) $monthHours->sum('minutes'),
                'tiers' => self::tierCounts($monthItems),
                'editors' => $perEditor,
            ]);

            $cursor->addMonthNoOverflow();
        }

        return $months;
    }

    /**
     * @param  Collection<int, ContentItem>  $items
     * @return array<string, int>
     */
    private static function tierCounts(Collection $items): array
    {
        $counts = array_fill_keys(array_keys(self::TIER_LABELS), 0);

        foreach ($items as $item) {
            $counts[self::tierOf($item->tier)]++;
        }

        return $counts;
    }

    /**
     * Two names in one cell -- "Aron, Sanjai". Left out of every per-person
     * figure rather than credited to both, which would invent output, or to the
     * first, which would rob the second.
     */
    private static function isShared(?string $editor): bool
    {
        return str_contains((string) $editor, ',');
    }

    /** First word, lowercased. The only thing the two systems agree on. */
    private static function key(string $name): string
    {
        return mb_strtolower(trim(explode(' ', trim($name))[0]));
    }
}
