<?php

namespace App\Http\Controllers\My;

use App\Http\Controllers\Controller;
use App\Models\Routine;
use App\Models\RoutineOccurrence;
use App\Services\RoutineCompleter;
use App\Services\RoutineScheduler;
use App\Support\RoutineDutyList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * An employee's own routine duties.
 *
 * Every query is scoped to routines they are permitted on (and, in
 * individual mode, rows assigned to them). Ownership is enforced here --
 * not merely by which links get rendered.
 */
class RoutineController extends Controller
{
    public function __construct(
        private readonly RoutineCompleter $completer,
        private readonly RoutineScheduler $scheduler,
    ) {}

    public function index(Request $request): View
    {
        $today = today();

        $duties = RoutineDutyList::group($this->visibleOpenOccurrences($request)->get());
        $due = $duties->filter(fn (array $d) => $d['oldest']->due_on->lte($today))->values();

        return view('my.routines', [
            // Fifteen venture accounts under one routine read as one task
            // with a checklist, not fifteen identical cards -- see
            // RoutineDutyList::nest().
            'tasks' => RoutineDutyList::nest($due),
            'upcoming' => $this->upcomingFor($request),
            'today' => $today,
        ]);
    }

    /**
     * Tick one or more duties in a single request.
     *
     * The page posts every ticked duty at once so a person with a dozen
     * duties reloads once, not a dozen times. A duty that is days behind
     * closes its whole backlog -- you cleaned the office, you did not clean
     * it once for each day you missed.
     */
    public function completeMany(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'duties' => ['required', 'array', 'min:1'],
            'duties.*' => ['string', 'max:191'],
            'values' => ['nullable', 'array'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $wanted = array_flip($data['duties']);

        // Re-resolve from the authorised query rather than trusting the posted
        // keys: a forged key simply matches nothing.
        $open = $this->visibleOpenOccurrences($request)->get();

        $chosen = $open->filter(
            fn (RoutineOccurrence $o) => isset($wanted[RoutineDutyList::keyFor($o)])
        );

        if ($chosen->isEmpty()) {
            return redirect()->route('my.routines')
                ->with('status', 'Nothing to save — those duties were already closed.');
        }

        $done = 0;
        $already = 0;

        // Group so each duty's own capture values are applied to its own rows.
        foreach ($chosen->groupBy(fn (RoutineOccurrence $o) => RoutineDutyList::keyFor($o)) as $key => $rows) {
            $result = $this->completer->completeMany(
                $rows->sortBy('due_on'),
                $request->user(),
                $data['values'][$key] ?? [],
                $data['note'] ?? null,
            );

            $done += $result['done'];
            $already += $result['already'];
        }

        return redirect()
            ->route('my.routines')
            ->with('status', $this->savedMessage($done, $already));
    }

    /**
     * Kept for the single-duty path and any bookmarked form posts.
     */
    public function complete(Request $request, RoutineOccurrence $occurrence): RedirectResponse
    {
        $this->authoriseVisible($request, $occurrence);

        $data = $request->validate([
            'values' => ['nullable', 'array'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $result = $this->completer->complete(
            $occurrence,
            $request->user(),
            $data['values'] ?? [],
            $data['note'] ?? null,
        );

        if (! $result['ok']) {
            $winner = $result['winner'];

            return redirect()
                ->route('my.routines')
                ->with('status', $winner
                    ? 'Already done by '.$winner->name.'.'
                    : 'That duty was already closed.');
        }

        return redirect()
            ->route('my.routines')
            ->with('status', 'Marked done.');
    }

    /**
     * Open occurrences this person is allowed to see and act on.
     *
     * Shared with completeMany() on purpose: the list you can tick and the
     * list the save accepts are the same query, so they cannot disagree.
     */
    private function visibleOpenOccurrences(Request $request)
    {
        $user = $request->user();

        $query = RoutineOccurrence::query()
            ->with(['routine.fields', 'checkpoint', 'subject', 'assignedUser', 'completedByUser'])
            ->where('status', RoutineOccurrence::STATUS_OPEN)
            ->whereDate('due_on', '<=', today()->toDateString())
            ->where(function ($q) use ($user) {
                $q->whereNull('assigned_user_id')
                    ->orWhere('assigned_user_id', $user->id);
            })
            ->orderBy('due_on')
            ->orderBy('id');

        if ($user->isAdmin()) {
            $query->whereHas('routine', fn ($q) => $q->where('is_active', true));
        } else {
            $query->whereHas('routine', fn ($q) => $q->where('is_active', true)
                ->whereHas('users', fn ($u) => $u->where('users.id', $user->id)));
        }

        return $query;
    }

    /**
     * The next few due dates per permitted routine.
     *
     * Computed from the scheduler rather than read from the table: generation
     * stops at today, so future rows do not exist -- and should not, since an
     * open row dated tomorrow would read as outstanding work.
     *
     * @return Collection<int, array{routine: Routine, dates: Collection}>
     */
    private function upcomingFor(Request $request): Collection
    {
        $user = $request->user();

        $routines = Routine::query()
            ->active()
            ->when(
                ! $user->isAdmin(),
                fn ($q) => $q->whereHas('users', fn ($u) => $u->where('users.id', $user->id))
            )
            ->orderBy('title')
            ->get();

        return $routines
            ->map(fn (Routine $routine) => [
                'routine' => $routine,
                'dates' => $this->scheduler->nextDatesAfter($routine, today(), 2),
            ])
            ->filter(fn (array $row) => $row['dates']->isNotEmpty())
            ->values();
    }

    private function savedMessage(int $done, int $already): string
    {
        if ($done > 0 && $already > 0) {
            return "Marked {$done} done. {$already} had already been closed by somebody else.";
        }

        if ($done > 0) {
            return $done === 1 ? 'Marked done.' : "Marked {$done} duties done.";
        }

        return 'Those duties had already been closed by somebody else.';
    }

    /**
     * 404 rather than 403: an employee has no business learning that another
     * person's individual duty exists.
     */
    private function authoriseVisible(Request $request, RoutineOccurrence $occurrence): void
    {
        $user = $request->user();
        $occurrence->loadMissing('routine.users');

        if ($user->isAdmin()) {
            return;
        }

        $routine = $occurrence->routine;
        abort_unless($routine && $routine->users->contains('id', $user->id), 404);

        if ($routine->isIndividual()) {
            abort_unless((int) $occurrence->assigned_user_id === (int) $user->id, 404);
        }
    }
}
