<?php

namespace App\Http\Controllers\My;

use App\Http\Controllers\Controller;
use App\Models\TimesheetDay;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Support\Attendance;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Throwable;

/**
 * A manager's view of their own team, decided a day at a time.
 *
 * Scoped to the signed-in manager's reports, the same way the rest of the my/
 * area scopes to the signed-in user. An admin sees every employee, because an
 * admin has to be able to cover for a manager who is on a shoot.
 */
class TeamController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        abort_unless($user->isAdmin() || $user->managesAnyone(), 403);

        $month = $this->resolveMonth($request->query('month'));

        $team = $user->isAdmin()
            ? User::where('role', User::ROLE_EMPLOYEE)->orderBy('name')->get()
            : $user->reports()->orderBy('name')->get();

        $entries = TimesheetEntry::whereIn('user_id', $team->pluck('id'))
            ->forMonth($month)
            ->orderBy('worked_on')
            ->get();

        $decisions = TimesheetDay::whereIn('user_id', $team->pluck('id'))
            ->whereBetween('worked_on', [
                $month->copy()->startOfMonth()->toDateString(),
                $month->copy()->endOfMonth()->toDateString(),
            ])
            ->get()
            ->keyBy(fn (TimesheetDay $day) => $day->user_id.'|'.$day->worked_on->toDateString());

        /*
         * One card per person per day that has any work on it. A day with no
         * entries is an absence, counted separately -- there is nothing to
         * decide about a day nobody worked.
         */
        $days = $entries
            ->groupBy(fn (TimesheetEntry $entry) => $entry->user_id.'|'.$entry->worked_on->toDateString())
            ->map(function ($rows, $key) use ($team, $decisions) {
                [$userId, $date] = explode('|', $key);

                return [
                    'key' => $key,
                    'member' => $team->firstWhere('id', (int) $userId),
                    'date' => Carbon::parse($date),
                    'entries' => $rows->sortBy('started_at')->values(),
                    'minutes' => (int) $rows->sum('minutes'),
                    'decision' => $decisions->get($key),
                ];
            })
            ->sortByDesc(fn (array $day) => $day['date']->timestamp)
            ->values();

        $absences = Attendance::countsFor($team, $month);

        $rows = $team->map(function (User $member) use ($days, $absences) {
            $theirs = $days->where('member.id', $member->id);

            return [
                'member' => $member,
                'minutes' => (int) $theirs->sum('minutes'),
                'days' => $theirs->count(),
                'absent' => (int) ($absences[$member->id] ?? 0),
                'waiting' => $theirs->whereNull('decision')->count(),
            ];
        })->sortByDesc('waiting')->values();

        return view('my.team', [
            'month' => $month,
            'rows' => $rows,
            'queue' => $days->whereNull('decision')->values(),
            'decided' => $days->whereNotNull('decision')->values(),
            'totalMinutes' => $rows->sum('minutes'),
            'totalAbsent' => $rows->sum('absent'),
            'workingDays' => Attendance::workingDaysSoFar($month)->count(),
        ]);
    }

    private function resolveMonth(?string $value): Carbon
    {
        if (! $value) {
            return now()->startOfMonth();
        }

        try {
            return Carbon::parse(strlen($value) === 7 ? $value.'-01' : $value)->startOfMonth();
        } catch (Throwable) {
            return now()->startOfMonth();
        }
    }
}
