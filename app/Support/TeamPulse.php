<?php

namespace App\Support;

use App\Models\TimesheetDay;
use App\Models\TimesheetEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The team's shape this month: who worked how long, and who has gone quiet.
 *
 * Shared by the admin dashboard and the timesheet overview so the two cannot
 * disagree about who is behind -- they were computing it separately, and a
 * dashboard that says everyone is fine while the timesheet list flags two
 * people is worse than either screen alone.
 */
class TeamPulse
{
    /** @return Collection<int, User> */
    public static function employees(): Collection
    {
        return User::where('role', User::ROLE_EMPLOYEE)->orderBy('name')->get();
    }

    /**
     * Hours logged this month per person, busiest first, with the count of
     * days still waiting on a manager's decision.
     *
     * @param  Collection<int, User>  $employees
     * @return Collection<int, array{employee: User, minutes: int, pending: int}>
     */
    public static function hours(Collection $employees, Carbon $month): Collection
    {
        if ($employees->isEmpty()) {
            return collect();
        }

        $entries = TimesheetEntry::whereIn('user_id', $employees->pluck('id'))
            ->forMonth($month)
            ->get()
            ->groupBy('user_id');

        $decisions = TimesheetDay::decisionsFor($employees->pluck('id'), $month);

        return $employees
            ->map(function (User $employee) use ($entries, $decisions) {
                $counted = $entries->get($employee->id, collect())
                    ->where('status', '!=', TimesheetEntry::STATUS_CANCELLED);

                return [
                    'employee' => $employee,
                    'minutes' => (int) $counted->sum('minutes'),
                    'pending' => $counted
                        ->pluck('worked_on')->map->toDateString()->unique()
                        ->reject(fn (string $date) => $decisions->has($employee->id.'|'.$date))
                        ->count(),
                ];
            })
            ->sortByDesc('minutes')
            ->values();
    }

    /**
     * Who has logged nothing so far this week.
     *
     * Measured against the current week rather than any month being viewed:
     * this answers "who do I chase today", which is a question about now.
     * Cancelled entries do not count as having logged. Longest silence first,
     * with the date they last logged anything so a two-day gap reads
     * differently from someone who has never started.
     *
     * @param  Collection<int, User>  $employees
     * @return Collection<int, array{employee: User, last: ?Carbon, daysSince: ?int}>
     */
    public static function behind(Collection $employees): Collection
    {
        if ($employees->isEmpty()) {
            return collect();
        }

        $ids = $employees->pluck('id');
        $weekStart = now()->startOfWeek();

        // Half-open range: worked_on is cast, so a stored "2026-08-11 00:00:00"
        // is not <= "2026-08-11". See TimesheetEntry::scopeForMonth.
        $loggedThisWeek = TimesheetEntry::whereIn('user_id', $ids)
            ->where('status', '!=', TimesheetEntry::STATUS_CANCELLED)
            ->where('worked_on', '>=', $weekStart->toDateString())
            ->where('worked_on', '<', now()->addDay()->startOfDay()->toDateString())
            ->distinct()
            ->pluck('user_id');

        $lastLogged = TimesheetEntry::whereIn('user_id', $ids)
            ->where('status', '!=', TimesheetEntry::STATUS_CANCELLED)
            ->selectRaw('user_id, MAX(worked_on) as last_worked_on')
            ->groupBy('user_id')
            ->pluck('last_worked_on', 'user_id');

        return $employees
            ->reject(fn (User $employee) => $loggedThisWeek->contains($employee->id))
            ->map(function (User $employee) use ($lastLogged) {
                $last = $lastLogged->get($employee->id);
                $last = $last ? Carbon::parse($last) : null;

                return [
                    'employee' => $employee,
                    'last' => $last,
                    'daysSince' => $last?->diffInDays(now()->startOfDay()),
                ];
            })
            ->sortByDesc(fn (array $row) => $row['daysSince'] ?? PHP_INT_MAX)
            ->values();
    }
}
