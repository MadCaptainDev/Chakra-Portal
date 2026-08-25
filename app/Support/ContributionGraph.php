<?php

namespace App\Support;

use App\Models\TimesheetEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The studio's work as one square per day, over a window you can change.
 *
 * The point is shape rather than precision: which weeks the studio was flat
 * out, where the quiet days were, and whether this month looks like last.
 * Nobody reads an exact figure off a 10px square, and a table of 365 numbers
 * answers none of those questions.
 *
 * Intensity is banded against the busiest day *in the chosen window*, not a
 * fixed number of hours. A studio of three and a studio of thirty both want the
 * same picture -- "busy for us" -- and a fixed scale gives one of them a solid
 * block and the other an empty one. It also means switching to This week
 * re-reads the week on its own terms rather than against a shoot day in March.
 *
 * Every window is built from a single query so the dropdown switches without a
 * round trip.
 */
class ContributionGraph
{
    public const WEEK = 'week';

    public const MONTH = 'month';

    public const LAST_MONTH = 'last_month';

    public const QUARTER = 'quarter';

    /** @var array<string, string> */
    public const RANGES = [
        self::WEEK => 'This week',
        self::MONTH => 'This month',
        self::LAST_MONTH => 'Last month',
        self::QUARTER => 'Last 3 months',
    ];

    public const DEFAULT_RANGE = self::MONTH;

    /**
     * How many week-columns each window spans, and how big its squares are.
     *
     * A seven-day window drawn at quarter size is a smudge, so the shorter the
     * window the larger the square. `unit` is the column pitch in pixels --
     * square plus gap -- which the month header needs to line its labels up
     * with the grid underneath.
     *
     * @var array<string, array{weeks: int, cell: string, gap: string, unit: int}>
     */
    private const SHAPE = [
        self::WEEK => ['weeks' => 1, 'cell' => 'w-9 h-9', 'gap' => 'gap-1.5', 'unit' => 42],
        self::MONTH => ['weeks' => 6, 'cell' => 'w-6 h-6', 'gap' => 'gap-1', 'unit' => 28],
        self::LAST_MONTH => ['weeks' => 6, 'cell' => 'w-6 h-6', 'gap' => 'gap-1', 'unit' => 28],
        self::QUARTER => ['weeks' => 13, 'cell' => 'w-3.5 h-3.5', 'gap' => 'gap-1', 'unit' => 18],
    ];

    /**
     * Every window, keyed by range. One query serves all of them.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function forTeam(?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy()->startOfDay();

        /*
         * Fetch once, over the widest window any range can ask for, then slice.
         * Four queries for four grids that all read the same rows would be
         * three queries too many.
         */
        $widest = $now->copy()->subWeeks(self::SHAPE[self::QUARTER]['weeks'])->startOfWeek(Carbon::SUNDAY);
        $lastMonthStart = $now->copy()->subMonthNoOverflow()->startOfMonth()->startOfWeek(Carbon::SUNDAY);

        if ($lastMonthStart->lt($widest)) {
            $widest = $lastMonthStart;
        }

        $minutesByDay = self::minutesByDay($widest, $now->copy()->endOfWeek(Carbon::SATURDAY));
        $peopleByDay = self::peopleByDay($widest, $now->copy()->endOfWeek(Carbon::SATURDAY));

        $graphs = [];

        foreach (array_keys(self::RANGES) as $range) {
            $graphs[$range] = self::build($range, $now, $minutesByDay, $peopleByDay);
        }

        return $graphs;
    }

    public static function isKnownRange(string $range): bool
    {
        return isset(self::RANGES[$range]);
    }

    /**
     * @param  Collection<string, int>  $minutesByDay
     * @param  Collection<string, list<array{name: string, minutes: int}>>  $peopleByDay
     * @return array<string, mixed>
     */
    private static function build(string $range, Carbon $now, Collection $minutesByDay, Collection $peopleByDay): array
    {
        $shape = self::SHAPE[$range];

        [$start, $confineTo] = self::windowFor($range, $now, $shape);

        /*
         * Two passes. The first lays out the cells and works out the busiest
         * day; the second bands them against it. Banding cannot happen in the
         * first pass because the maximum is not known until the last cell is
         * read, and this window's maximum is the whole basis of the colour.
         */
        $cells = [];
        $cursor = $start->copy();

        for ($week = 0; $week < $shape['weeks']; $week++) {
            $column = [];

            for ($day = 0; $day < 7; $day++) {
                $column[] = self::cell($cursor, $now, $confineTo, $minutesByDay, $peopleByDay);
                $cursor->addDay();
            }

            $cells[] = $column;
        }

        $shown = collect($cells)->flatten(1)->filter();
        $max = (int) ($shown->max('minutes') ?: 0);

        $weeks = collect($cells)->map(
            fn (array $column) => collect($column)->map(function (?array $day) use ($max) {
                if ($day === null) {
                    return null;
                }

                $day['level'] = self::level($day['minutes'], $max);

                return $day;
            })->all()
        )->all();

        $busiest = $shown->where('minutes', '>', 0)->sortByDesc('minutes')->first();

        return [
            'key' => $range,
            'label' => self::RANGES[$range],
            'caption' => self::caption($range, $shown, $confineTo),
            'weeks' => $weeks,
            // A single month needs no month header -- its own caption says so.
            'months' => $range === self::QUARTER ? self::monthHeader($cells) : [],
            'cell' => $shape['cell'],
            'gap' => $shape['gap'],
            'unit' => $shape['unit'],
            'total' => (int) $shown->sum('minutes'),
            'daysWorked' => $shown->where('minutes', '>', 0)->count(),
            'busiest' => $busiest ? ['date' => $busiest['date'], 'minutes' => $busiest['minutes']] : null,
        ];
    }

    /**
     * Where the grid starts, and the calendar month it is confined to if any.
     *
     * This month / last month are drawn as calendar months: the grid still
     * starts on a Sunday, but the days either side belong to another month and
     * are left out so the shape reads the way a wall calendar does.
     *
     * @param  array{weeks: int, cell: string, gap: string, unit: int}  $shape
     * @return array{0: Carbon, 1: ?Carbon}
     */
    private static function windowFor(string $range, Carbon $now, array $shape): array
    {
        if ($range === self::MONTH) {
            return [$now->copy()->startOfMonth()->startOfWeek(Carbon::SUNDAY), $now->copy()->startOfMonth()];
        }

        if ($range === self::LAST_MONTH) {
            $month = $now->copy()->subMonthNoOverflow()->startOfMonth();

            return [$month->copy()->startOfWeek(Carbon::SUNDAY), $month];
        }

        $end = $now->copy()->endOfWeek(Carbon::SATURDAY);

        return [$end->copy()->subWeeks($shape['weeks'] - 1)->startOfWeek(Carbon::SUNDAY), null];
    }

    /**
     * @param  Collection<string, int>  $minutesByDay
     * @param  Collection<string, list<array{name: string, minutes: int}>>  $peopleByDay
     * @return array{date: Carbon, dateLabel: string, minutes: int, hoursLabel: string, people: list<array{name: string, minutes: int, hoursLabel: string}>, level: int}|null
     */
    private static function cell(
        Carbon $date,
        Carbon $now,
        ?Carbon $confineTo,
        Collection $minutesByDay,
        Collection $peopleByDay,
    ): ?array {
        // A day after today has not happened; a day outside the month being
        // shown is somebody else's square. Neither is a quiet day.
        if ($date->gt($now) || ($confineTo && ! $date->isSameMonth($confineTo))) {
            return null;
        }

        $key = $date->toDateString();
        $minutes = (int) $minutesByDay->get($key, 0);
        $people = collect($peopleByDay->get($key, []))
            ->map(fn (array $person) => [
                'name' => $person['name'],
                'minutes' => $person['minutes'],
                'hoursLabel' => TimesheetEntry::formatMinutes($person['minutes']),
            ])
            ->values()
            ->all();

        return [
            'date' => $date->copy(),
            'dateLabel' => $date->format('D j M Y'),
            'minutes' => $minutes,
            'hoursLabel' => $minutes > 0 ? TimesheetEntry::formatMinutes($minutes) : 'Nothing logged',
            'people' => $people,
            'level' => 0,
        ];
    }

    /**
     * One label per month, spanning however many columns started in it.
     *
     * @param  list<list<array<string, mixed>|null>>  $cells
     * @return list<array{label: string, span: int}>
     */
    private static function monthHeader(array $cells): array
    {
        $months = [];
        $last = null;

        foreach ($cells as $column) {
            // The column's own month, taken from its first day -- which is
            // there even when every square in it is still in the future.
            $first = collect($column)->filter()->first();
            $key = $first ? $first['date']->format('Y-m') : null;

            if ($key === null) {
                continue;
            }

            if ($key !== $last) {
                $months[] = ['label' => $first['date']->format('M'), 'span' => 1];
                $last = $key;
            } else {
                $months[count($months) - 1]['span']++;
            }
        }

        return $months;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $shown
     */
    private static function caption(string $range, Collection $shown, ?Carbon $confineTo): string
    {
        if ($confineTo) {
            return $confineTo->format('F Y');
        }

        $first = $shown->first();
        $last = $shown->last();

        if (! $first || ! $last) {
            return '';
        }

        return match ($range) {
            self::WEEK => $first['date']->format('d M').' – '.$last['date']->format('d M Y'),
            default => $first['date']->format('M Y').' – '.$last['date']->format('M Y'),
        };
    }

    /**
     * Minutes per calendar day across everyone, keyed "Y-m-d".
     *
     * @return Collection<string, int>
     */
    private static function minutesByDay(Carbon $start, Carbon $end): Collection
    {
        return TimesheetEntry::query()
            ->where('status', '!=', TimesheetEntry::STATUS_CANCELLED)
            ->where('worked_on', '>=', $start->toDateString())
            ->where('worked_on', '<', $end->copy()->addDay()->toDateString())
            ->get(['worked_on', 'minutes'])
            ->groupBy(fn (TimesheetEntry $entry) => $entry->worked_on->toDateString())
            ->map(fn (Collection $rows) => (int) $rows->sum('minutes'));
    }

    /**
     * Who worked each day and for how long, keyed "Y-m-d".
     *
     * @return Collection<string, list<array{name: string, minutes: int}>>
     */
    private static function peopleByDay(Carbon $start, Carbon $end): Collection
    {
        return TimesheetEntry::query()
            ->with(['user:id,name'])
            ->where('status', '!=', TimesheetEntry::STATUS_CANCELLED)
            ->where('worked_on', '>=', $start->toDateString())
            ->where('worked_on', '<', $end->copy()->addDay()->toDateString())
            ->get(['user_id', 'worked_on', 'minutes'])
            ->groupBy(fn (TimesheetEntry $entry) => $entry->worked_on->toDateString())
            ->map(function (Collection $rows) {
                return $rows
                    ->groupBy('user_id')
                    ->map(fn (Collection $personRows) => [
                        'name' => $personRows->first()->user?->name ?? 'Unknown',
                        'minutes' => (int) $personRows->sum('minutes'),
                    ])
                    ->sortByDesc('minutes')
                    ->values()
                    ->all();
            });
    }

    /**
     * 0 for a day with nothing on it, then four bands up to the busiest day.
     *
     * A day with any work at all is never level 0 -- an hour logged on a quiet
     * Sunday must not read the same as a day nobody worked, which is the one
     * distinction the whole picture rests on.
     */
    private static function level(int $minutes, int $max): int
    {
        if ($minutes <= 0 || $max <= 0) {
            return 0;
        }

        return max(1, (int) ceil(($minutes / $max) * 4));
    }
}
