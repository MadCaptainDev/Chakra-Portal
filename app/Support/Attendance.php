<?php

namespace App\Support;

use App\Models\TimesheetEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Which working days somebody logged nothing on.
 *
 * Two rules keep this honest, and both matter more than they look:
 *
 *   Working days only. Read literally, "a day with no timesheet is an absence"
 *   makes everyone absent every Sunday for ever. The studio works Monday to
 *   Saturday, so Sunday is simply blank rather than a mark against anybody.
 *
 *   Never into the future. Nobody is absent tomorrow. Counting forward would
 *   put an unbounded and growing number of absences against every person on
 *   the team, which is the fastest way to make a number nobody looks at.
 *
 * Derived on every call rather than stored. An absence is the absence of a
 * record; giving it a record of its own creates two things that can disagree,
 * and then someone has to decide which is true.
 */
class Attendance
{
    /**
     * Days of the week that count as working days, Carbon's numbering
     * (0 = Sunday). Monday to Saturday.
     *
     * @var list<int>
     */
    public const WORKING_DAYS = [1, 2, 3, 4, 5, 6];

    public static function isWorkingDay(Carbon $date): bool
    {
        return in_array($date->dayOfWeek, self::WORKING_DAYS, true);
    }

    /**
     * Working days in the month, up to today, that carry no entry at all.
     *
     * A cancelled entry does not count as having worked -- it is the record of
     * something that did not happen.
     *
     * @return Collection<int, Carbon>
     */
    public static function absencesFor(User $user, Carbon $month): Collection
    {
        $logged = TimesheetEntry::where('user_id', $user->id)
            ->forMonth($month)
            ->where('status', '!=', TimesheetEntry::STATUS_CANCELLED)
            ->pluck('worked_on')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->unique()
            ->flip();

        return self::workingDaysSoFar($month)
            ->reject(fn (Carbon $day) => $logged->has($day->toDateString()))
            ->values();
    }

    /**
     * Every working day of the month that has already happened.
     *
     * The month being viewed may be in the past, in which case all of its
     * working days count; if it is the current month it stops at today; and a
     * future month has none at all.
     *
     * @return Collection<int, Carbon>
     */
    public static function workingDaysSoFar(Carbon $month): Collection
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $today = now()->endOfDay();

        if ($start->greaterThan($today)) {
            return collect();
        }

        if ($end->greaterThan($today)) {
            $end = $today;
        }

        $days = collect();

        for ($day = $start->copy(); $day->lessThanOrEqualTo($end); $day->addDay()) {
            if (self::isWorkingDay($day)) {
                $days->push($day->copy()->startOfDay());
            }
        }

        return $days;
    }

    /**
     * Absence counts for a set of people in one month, keyed by user id.
     *
     * One query for the whole team rather than one per person -- the team
     * timesheet screen asks this for everybody at once.
     *
     * @param  Collection<int, User>  $users
     * @return Collection<int, int>
     */
    public static function countsFor(Collection $users, Carbon $month): Collection
    {
        if ($users->isEmpty()) {
            return collect();
        }

        $expected = self::workingDaysSoFar($month)->map->toDateString();

        if ($expected->isEmpty()) {
            return $users->mapWithKeys(fn (User $user) => [$user->id => 0]);
        }

        $logged = TimesheetEntry::whereIn('user_id', $users->pluck('id'))
            ->forMonth($month)
            ->where('status', '!=', TimesheetEntry::STATUS_CANCELLED)
            ->get(['user_id', 'worked_on'])
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows
                ->map(fn ($row) => Carbon::parse($row->worked_on)->toDateString())
                ->unique()
                ->flip());

        return $users->mapWithKeys(function (User $user) use ($expected, $logged) {
            $theirs = $logged->get($user->id, collect());

            return [$user->id => $expected->reject(fn (string $day) => $theirs->has($day))->count()];
        });
    }
}
