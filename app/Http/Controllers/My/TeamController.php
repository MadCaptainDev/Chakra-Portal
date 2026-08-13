<?php

namespace App\Http\Controllers\My;

use App\Http\Controllers\Controller;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Support\Attendance;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Throwable;

/**
 * A manager's view of their own team.
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

        // Nobody reports to this person and they are not an admin: there is no
        // team to show, and a 403 explains that better than an empty screen.
        abort_unless($user->isAdmin() || $user->managesAnyone(), 403);

        $month = $this->resolveMonth($request->query('month'));

        $team = $user->isAdmin()
            ? User::where('role', User::ROLE_EMPLOYEE)->orderBy('name')->get()
            : $user->reports()->orderBy('name')->get();

        $entries = TimesheetEntry::whereIn('user_id', $team->pluck('id'))
            ->forMonth($month)
            ->with('user')
            ->get();

        $byPerson = $entries->groupBy('user_id');
        $absences = Attendance::countsFor($team, $month);

        $rows = $team->map(function (User $member) use ($byPerson, $absences) {
            $own = $byPerson->get($member->id, collect());
            $counted = $own->where('status', '!=', TimesheetEntry::STATUS_CANCELLED);

            return [
                'member' => $member,
                'minutes' => (int) $counted->sum('minutes'),
                'days' => $counted->pluck('worked_on')->map->toDateString()->unique()->count(),
                'absent' => (int) ($absences[$member->id] ?? 0),
                // Late-filed or edited, and nobody has decided yet.
                'awaiting' => $own->filter(fn (TimesheetEntry $e) => $e->needsReview())->count(),
            ];
        })->sortByDesc('awaiting')->values();

        /*
         * The queue is the reason a manager opens this screen, so it is loaded
         * whole rather than paged -- if it is ever long enough to need paging,
         * something has gone wrong upstream that paging would only hide.
         */
        $queue = $entries
            ->filter(fn (TimesheetEntry $entry) => $entry->needsReview())
            ->sortBy('worked_on')
            ->values();

        return view('my.team', [
            'month' => $month,
            'rows' => $rows,
            'queue' => $queue,
            'totalMinutes' => $rows->sum('minutes'),
            'totalAbsent' => $rows->sum('absent'),
            'workingDays' => Attendance::workingDaysSoFar($month)->count(),
        ]);
    }

    /** Same shape as every other month-scoped screen in the app. */
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
