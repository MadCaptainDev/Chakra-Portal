<?php

namespace App\Http\Controllers\My;

use App\Http\Controllers\Controller;
use App\Models\Todo;
use App\Models\TodoUpdate;
use App\Models\User;
use App\Notifications\TodoAssigned;
use App\Support\PeriodInput;
use App\Support\TimesheetVenture;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * One person's to-do list, a day at a time.
 *
 * Anybody may write a to-do for anybody -- a producer hands an edit over, a
 * client call turns into three jobs for three people. What that does NOT do is
 * open the list up: acting on a to-do is still limited to the two people it
 * concerns, the one who has to do it and the one who asked. Everyone else gets
 * a 404, tracker or no tracker.
 *
 * Who it is for is fixed when it is written. Moving work to somebody else is a
 * new to-do, not an edit -- the history on this one records what happened to
 * the job that was handed over, and silently swapping the name at the top would
 * make that record describe somebody who never touched it.
 */
class TodoController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $day = PeriodInput::day($request->query('date'));

        $todos = $this->baseQuery($user->id)->onDay($day)->get();

        return view('my.todos', [
            'day' => $day,
            'todos' => $this->forBoard($todos),
            /*
             * Work that has not started yet. The ask was a tracker for "all at
             * once", and a board that shows only today hides the two-day job
             * somebody lined up for Thursday until Thursday.
             */
            'later' => $this->forBoard(
                $this->baseQuery($user->id)
                    ->open()
                    ->where('starts_on', '>=', $day->copy()->addDay()->toDateString())
                    ->get()
            ),
            /*
             * What this person has given other people for this day. Without it,
             * handing work over is a thing you do and then never see again --
             * and only managers can reach the tracker.
             */
            'assigned' => $this->forBoard(
                Todo::where('assigned_by_id', $user->id)
                    ->where('user_id', '!=', $user->id)
                    ->onDay($day)
                    ->with(['updates.user', 'user', 'reviewer'])
                    ->withCount(['updates as deferrals_count' => fn ($query) => $query->where('action', TodoUpdate::MOVED)])
                    ->get()
            ),
            'statuses' => Todo::STATUSES,
            'ventureOptions' => TimesheetVenture::options(),
            // Anyone can be given a job, so the picker is everybody with a login.
            'people' => User::staff()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, creating: true);
        $data['assigned_by_id'] = $request->user()->id;

        $todo = Todo::create($data);

        TodoUpdate::record($todo, $request->user(), TodoUpdate::CREATED, [
            'to_status' => $todo->status,
            'from_on' => $todo->starts_on,
            'to_on' => $todo->due_on,
        ]);

        TodoAssigned::notifyIfAssigned($todo);

        $span = $todo->spanDays() > 1
            ? " — {$todo->spanDays()} days, due {$todo->due_on->format('D j M')}"
            : '';

        return back()->with('status', $todo->isSelfAssigned()
            ? "To-do added{$span}."
            : "Assigned to {$todo->user->name}{$span}.");
    }

    public function update(Request $request, Todo $todo): RedirectResponse
    {
        $this->authoriseWrite($request, $todo);

        $data = $this->validated($request, creating: false);

        $wasDue = $todo->due_on->copy();
        $todo->fill($data);

        // Nothing to record when nothing moved -- an "edited" row against an
        // unchanged to-do is noise in the one place that has to stay readable.
        if ($todo->isDirty()) {
            $todo->save();

            TodoUpdate::record($todo, $request->user(), TodoUpdate::EDITED, [
                'from_on' => $wasDue->isSameDay($todo->due_on) ? null : $wasDue,
                'to_on' => $wasDue->isSameDay($todo->due_on) ? null : $todo->due_on,
            ]);
        }

        return back()->with('status', 'To-do updated.');
    }

    public function destroy(Request $request, Todo $todo): RedirectResponse
    {
        $this->authoriseWrite($request, $todo);

        $todo->delete();

        return back()->with('status', 'To-do deleted.');
    }

    /**
     * One endpoint for every transition, with the target in a hidden field --
     * the same shape as deciding on a timesheet day.
     *
     * No transition is refused. People mis-tap, and blocked work unblocks; the
     * history is what makes that safe rather than a state machine that has to
     * be argued with at five o'clock.
     */
    public function status(Request $request, Todo $todo): RedirectResponse
    {
        $this->authoriseWrite($request, $todo);

        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Todo::STATUSES))],
            // Saying something is blocked without saying what by is the one
            // update that helps nobody.
            'note' => [
                Rule::requiredIf($request->input('status') === Todo::STATUS_BLOCKED),
                'nullable', 'string', 'max:1000',
            ],
        ]);

        $todo->moveTo($data['status'], $request->user(), $data['note'] ?? null);

        return back()->with('status', 'Marked '.strtolower($todo->statusLabel()).'.');
    }

    /** Push the promised day by one. */
    public function defer(Request $request, Todo $todo): RedirectResponse
    {
        $this->authoriseWrite($request, $todo);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        // A stale form is somebody's second tab, not an attack, so this says so
        // rather than aborting.
        if (! $todo->defer($request->user(), $data['note'] ?? null)) {
            return back()->with('status', 'That to-do is already finished, so there was nothing to move.');
        }

        return back()->with('status', "Moved to {$todo->due_on->format('D j M')}.");
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Todo>
     */
    private function baseQuery(int $userId)
    {
        return Todo::where('user_id', $userId)
            // The history is needed for the timeline and for replaying what the
            // status was on an older day, so it is loaded once here rather than
            // a query per row.
            ->with(['updates.user', 'assignedBy', 'user', 'reviewer'])
            ->withCount(['updates as deferrals_count' => fn ($query) => $query->where('action', TodoUpdate::MOVED)]);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Todo>  $todos
     * @return \Illuminate\Support\Collection<int, Todo>
     */
    private function forBoard($todos)
    {
        return $todos
            ->sortBy(fn (Todo $todo) => [$todo->boardRank(), $todo->due_on->toDateString(), $todo->id])
            ->values();
    }

    /**
     * 404 rather than 403: somebody with no part in a to-do has no business
     * learning that it exists.
     */
    private function authoriseWrite(Request $request, Todo $todo): void
    {
        abort_unless($todo->isWritableBy($request->user()), 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            // The same value set the timesheet uses, so a to-do and the entry
            // that eventually logs it name the client the same way.
            // "All / Multiple Clients" is in that list and covers work that is
            // not any one client's.
            'venture' => TimesheetVenture::validationRules(),
            'notes' => ['nullable', 'string', 'max:2000'],
            'starts_on' => ['required', 'date'],
            'due_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ];

        // Who it is for is settled when it is written, never on an edit.
        if ($creating) {
            $rules['user_id'] = ['required', 'integer', Rule::exists('users', 'id')];
        }

        $data = $request->validate($rules);

        // A one-day job is a range of one. Leaving due_on null instead would put
        // a null branch into overdue checks, the move handler and every badge.
        $data['due_on'] = $data['due_on'] ?? $data['starts_on'];

        // Same normalisation the timesheet does, so a client typed one way in
        // one place and another way here still lines up.
        $data['venture'] = TimesheetVenture::normalize(trim((string) $data['venture']))
            ?? trim((string) $data['venture']);

        return $data;
    }
}
