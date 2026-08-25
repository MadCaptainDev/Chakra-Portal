<?php

namespace App\Services;

use App\Models\Routine;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Pure date math for routine schedules. Anchored on starts_on; catch-up
 * windows are applied by the generator, not here.
 */
class RoutineScheduler
{
    /**
     * Every due date from $from through $through (inclusive) that the
     * routine's schedule produces, never before starts_on.
     *
     * @return Collection<int, Carbon>
     */
    public function datesBetween(Routine $routine, Carbon $from, Carbon $through): Collection
    {
        $startsOn = $routine->starts_on->copy()->startOfDay();
        $from = $from->copy()->startOfDay();
        $through = $through->copy()->startOfDay();

        if ($through->lt($startsOn)) {
            return collect();
        }

        if ($from->lt($startsOn)) {
            $from = $startsOn->copy();
        }

        return match ($routine->schedule_type) {
            Routine::SCHEDULE_DAILY => $this->eachDay($from, $through),
            Routine::SCHEDULE_EVERY_N_DAYS => $this->everyNDays($routine, $from, $through),
            Routine::SCHEDULE_WEEKDAYS => $this->weekdays($from, $through),
            Routine::SCHEDULE_MONTHLY => $this->monthly($routine, $from, $through),
            default => collect(),
        };
    }

    /**
     * The next $limit due dates strictly after $after.
     *
     * For display only. Generation deliberately stops at today, so nothing
     * beyond it exists as a row -- materialising future occurrences would make
     * them "open" and pollute every overdue query. "Coming up" is therefore
     * computed, not stored.
     *
     * @return Collection<int, Carbon>
     */
    public function nextDatesAfter(Routine $routine, Carbon $after, int $limit = 3): Collection
    {
        if ($limit < 1) {
            return collect();
        }

        $from = $after->copy()->startOfDay()->addDay();

        // A monthly routine can be up to a month out, and every_n_days up to N.
        // Widen the window enough that $limit dates are actually reachable
        // rather than returning short for sparse schedules.
        $span = match ($routine->schedule_type) {
            Routine::SCHEDULE_MONTHLY => 31 * ($limit + 1),
            Routine::SCHEDULE_EVERY_N_DAYS => max(1, (int) ($routine->schedule_interval ?: 1)) * ($limit + 1),
            default => 7 * $limit,
        };

        return $this->datesBetween($routine, $from, $from->copy()->addDays($span))
            ->take($limit)
            ->values();
    }

    /**
     * @return Collection<int, Carbon>
     */
    private function eachDay(Carbon $from, Carbon $through): Collection
    {
        $dates = collect();
        $cursor = $from->copy();

        while ($cursor->lte($through)) {
            $dates->push($cursor->copy());
            $cursor->addDay();
        }

        return $dates;
    }

    /**
     * Anchored on starts_on: due when (date - starts_on) % N === 0.
     *
     * @return Collection<int, Carbon>
     */
    private function everyNDays(Routine $routine, Carbon $from, Carbon $through): Collection
    {
        $n = max(1, (int) ($routine->schedule_interval ?: 1));
        $anchor = $routine->starts_on->copy()->startOfDay();
        $dates = collect();
        $cursor = $from->copy();

        while ($cursor->lte($through)) {
            if ($cursor->gte($anchor) && $anchor->diffInDays($cursor) % $n === 0) {
                $dates->push($cursor->copy());
            }
            $cursor->addDay();
        }

        return $dates;
    }

    /**
     * @return Collection<int, Carbon>
     */
    private function weekdays(Carbon $from, Carbon $through): Collection
    {
        $dates = collect();
        $cursor = $from->copy();

        while ($cursor->lte($through)) {
            if ($cursor->isWeekday()) {
                $dates->push($cursor->copy());
            }
            $cursor->addDay();
        }

        return $dates;
    }

    /**
     * Day-of-month, clamped to the last day of shorter months (31st → 28/29
     * in February, back to 31 when the month allows it).
     *
     * @return Collection<int, Carbon>
     */
    private function monthly(Routine $routine, Carbon $from, Carbon $through): Collection
    {
        $dom = (int) ($routine->day_of_month ?: $routine->starts_on->day);
        $dom = max(1, min(31, $dom));

        $dates = collect();
        $cursor = $from->copy()->startOfMonth();

        // Walk months that can intersect [from, through].
        while ($cursor->lte($through->copy()->endOfMonth())) {
            $day = min($dom, $cursor->daysInMonth);
            $candidate = $cursor->copy()->day($day)->startOfDay();

            if ($candidate->gte($from) && $candidate->lte($through) && $candidate->gte($routine->starts_on->copy()->startOfDay())) {
                $dates->push($candidate);
            }

            $cursor->addMonthNoOverflow()->startOfMonth();
        }

        return $dates;
    }
}
