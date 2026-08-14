<?php

namespace App\Http\Controllers\My;

use App\Http\Controllers\Controller;
use App\Models\Todo;
use App\Models\TodoUpdate;
use App\Support\PeriodInput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * An employee's own to-do list, a day at a time.
 *
 * Every query is scoped to the signed-in user, and ownership is enforced here
 * rather than by which links happen to get rendered. Employees write their own
 * to-dos; managers read them on the tracker and cannot change them, the same
 * way a manager decides on a timesheet day without editing its entries.
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
            'statuses' => Todo::STATUSES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['user_id'] = $request->user()->id;

        $todo = Todo::create($data);

        TodoUpdate::record($todo, $request->user(), TodoUpdate::CREATED, [
            'to_status' => $todo->status,
            'from_on' => $todo->starts_on,
            'to_on' => $todo->due_on,
        ]);

        return back()->with('status', $todo->spanDays() > 1
            ? "To-do added — {$todo->spanDays()} days, due {$todo->due_on->format('D j M')}."
            : 'To-do added.');
    }

    public function update(Request $request, Todo $todo): RedirectResponse
    {
        $this->authoriseOwnership($request, $todo);

        $data = $this->validated($request);

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
        $this->authoriseOwnership($request, $todo);

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
        $this->authoriseOwnership($request, $todo);

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
        $this->authoriseOwnership($request, $todo);

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
            ->with('updates.user')
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
     * 404 rather than 403: an employee has no business learning that another
     * person's to-do exists.
     */
    private function authoriseOwnership(Request $request, Todo $todo): void
    {
        abort_unless($todo->user_id === $request->user()->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'starts_on' => ['required', 'date'],
            'due_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ]);

        // A one-day job is a range of one. Leaving due_on null instead would put
        // a null branch into overdue checks, the move handler and every badge.
        $data['due_on'] = $data['due_on'] ?? $data['starts_on'];

        return $data;
    }
}
