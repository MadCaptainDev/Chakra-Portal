<?php

namespace App\Http\Controllers;

use App\Models\Todo;
use App\Models\TodoUpdate;
use App\Models\User;
use App\Support\PeriodInput;
use App\Support\TimesheetVenture;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Everybody's to-dos for one day, on one screen.
 *
 * Read-only on purpose. Employees write their own to-dos; a manager reading
 * this cannot start, finish or move somebody else's, which is the line the app
 * already draws for timesheets -- a manager decides on a day, never edits the
 * entries inside it.
 *
 * One screen serves both audiences, unlike timesheets which split into
 * my/team for managers and timesheets/ for admins. The split exists there
 * because the two answer different questions; here they want the same board and
 * differ only in who is on it.
 */
class TodoTrackerController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        abort_unless($user->isAdmin() || $user->managesAnyone(), 403);

        $day = PeriodInput::day($request->query('date'));

        $team = $user->isAdmin()
            ? User::whoLogWork()->orderBy('name')->get()
            : $user->reports()->orderBy('name')->get();

        /*
         * Narrowing to one person is a filter on this screen rather than a
         * screen of its own. 404 for anyone outside the caller's team: whether
         * a given account exists is not this endpoint's to answer.
         */
        $only = $request->query('user');

        if ($only) {
            $team = $team->where('id', (int) $only)->values();

            abort_if($team->isEmpty(), 404);
        }

        $status = $request->query('status');

        if (! array_key_exists((string) $status, Todo::STATUSES)) {
            $status = null;
        }

        // Same list the form offers, so a client that has since been renamed
        // cannot be filtered for and silently return nothing.
        $ventureOptions = TimesheetVenture::options();
        $venture = $request->query('venture');

        if (! in_array((string) $venture, array_column($ventureOptions, 'value'), true)) {
            $venture = null;
        }

        $todos = Todo::whereIn('user_id', $team->pluck('id'))
            ->onDay($day)
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($venture, fn ($query) => $query->where('venture', $venture))
            // The history is what says when each thing moved, and what the
            // status was on an older day. Loaded once for the whole board.
            ->with(['updates.user', 'assignedBy', 'user', 'reviewer'])
            ->withCount(['updates as deferrals_count' => fn ($query) => $query->where('action', TodoUpdate::MOVED)])
            ->get()
            ->sortBy(fn (Todo $todo) => [$todo->boardRank(), $todo->due_on->toDateString(), $todo->id])
            ->groupBy('user_id');

        return view('todos.index', [
            'day' => $day,
            'team' => $team,
            'todos' => $todos,
            'onlyUser' => $only ? (int) $only : null,
            'status' => $status,
            'statuses' => Todo::STATUSES,
            'venture' => $venture,
            'ventureOptions' => $ventureOptions,
            /*
             * Counted off the same rows the board renders, so the summary can
             * never disagree with the list under it.
             */
            'counts' => $todos->flatten(1)->countBy(
                fn (Todo $todo) => $todo->statusOn($day)
            ),
        ]);
    }
}
