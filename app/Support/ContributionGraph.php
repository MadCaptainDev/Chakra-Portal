<?php

namespace App\Support;

use App\Models\TimesheetEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A year of the studio's work as one square per day.
 *
 * The point is shape rather than precision: which months the studio was flat
 * out, where the quiet weeks were, and whether the last fortnight looks like
 * the rest of the year. Nobody reads an exact figure off a 10px square, and a
 * table of 365 numbers answers none of those questions.
 *
 * Intensity is banded against the busiest day in the window rather than a fixed
 * number of hours. A studio of three and a studio of thirty both want the same
 * picture -- "busy for us" -- and a fixed scale gives one of them a solid block
 * and the other an empty one.
 */
class ContributionGraph
{
    /** Sunday-first columns, the way a calendar reads. */
    public const WEEKS = 53;

    /**
     * @return array{
     *     weeks: list<list<array{date: Carbon, minutes: int, level: int}|null>>,
     *     months: list<array{label: string, span: int}>,
     *     total: int,
     *     busiest: array{date: Carbon, minutes: int}|null,
     *     daysWorked: int,
     * }
     */
    public static function forTeam(?Carbon $end = null): array
    {
        $end = ($end ?? now())->copy()->endOfWeek(Carbon::SATURDAY)->startOfDay();
        $start = $end->copy()->subWeeks(self::WEEKS - 1)->startOfWeek(Carbon::SUNDAY);

        $minutesByDay = self::minutesByDay($start, $end);
        $max = (int) ($minutesByDay->max() ?: 0);

        $weeks = [];
        $months = [];
        $cursor = $start->copy();
        $lastMonth = null;

        for ($week = 0; $week < self::WEEKS; $week++) {
            $column = [];

            /*
             * The month label belongs to whichever month the column starts in.
             * Labelling every column would be unreadable, so a month claims one
             * label and a span, and the header lays them out with the same
             * column width as the grid.
             */
            $monthKey = $cursor->format('Y-m');

            if ($monthKey !== $lastMonth) {
                $months[] = ['label' => $cursor->format('M'), 'span' => 1];
                $lastMonth = $monthKey;
            } else {
                $months[count($months) - 1]['span']++;
            }

            for ($day = 0; $day < 7; $day++) {
                // Days after today are not "nothing logged", they have not
                // happened. Left empty rather than shown as a quiet day.
                $column[] = $cursor->gt(now()->endOfDay())
                    ? null
                    : [
                        'date' => $cursor->copy(),
                        'minutes' => $minutes = (int) $minutesByDay->get($cursor->toDateString(), 0),
                        'level' => self::level($minutes, $max),
                    ];

                $cursor->addDay();
            }

            $weeks[] = $column;
        }

        $busiestDate = $minutesByDay->filter()->sortDesc()->keys()->first();

        return [
            'weeks' => $weeks,
            'months' => $months,
            'total' => (int) $minutesByDay->sum(),
            'busiest' => $busiestDate ? [
                'date' => Carbon::parse($busiestDate),
                'minutes' => (int) $minutesByDay->get($busiestDate),
            ] : null,
            'daysWorked' => $minutesByDay->filter()->count(),
        ];
    }

    /**
     * Minutes per calendar day across everyone, keyed "Y-m-d".
     *
     * Grouped in the database rather than by hydrating a year of entries. The
     * date is taken as a string prefix instead of a date function so MySQL and
     * SQLite agree: worked_on is a DATE column the model casts, which means the
     * stored value carries a midnight time on one of them and not the other.
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
