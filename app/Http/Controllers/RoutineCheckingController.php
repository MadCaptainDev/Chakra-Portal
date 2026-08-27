<?php

namespace App\Http\Controllers;

use App\Models\Routine;
use App\Models\RoutineOccurrence;
use App\Models\User;
use App\Services\RoutineCompleter;
use App\Support\RoutineDutyList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Throwable;

/**
 * "Who hasn't done their duties?" -- on one screen, on a phone.
 *
 * The calendar answers "what fell on the 14th", which is a question somebody
 * asks occasionally. This answers the one a manager asks every day, and is
 * therefore the module's front door. The same conclusion the timesheets
 * redesign reached when it replaced its calendar with a per-person queue.
 */
class RoutineCheckingController extends Controller
{
    public function __construct(private readonly RoutineCompleter $completer) {}

    public function index(Request $request): View
    {
        $day = $this->resolveDay($request->query('day'));

        // Everything still owed as of $day, including duties that fell earlier
        // and were never closed -- a duty three days late is exactly what this
        // screen exists to show, so it cannot be filtered out by date equality.
        $open = RoutineOccurrence::query()
            ->with(['routine', 'checkpoint', 'subject', 'assignedUser'])
            ->where('status', RoutineOccurrence::STATUS_OPEN)
            ->whereDate('due_on', '<=', $day->toDateString())
            ->whereHas('routine', fn ($q) => $q->where('is_active', true))
            ->get();

        $settledToday = RoutineOccurrence::query()
            ->with(['routine', 'checkpoint', 'subject', 'completedByUser'])
            ->whereIn('status', [RoutineOccurrence::STATUS_DONE, RoutineOccurrence::STATUS_SKIPPED])
            ->whereDate('due_on', $day->toDateString())
            ->get();

        return view('routines.checking', [
            'day' => $day,
            'groups' => $this->groupByPerson($open),
            'settled' => $settledToday,
            'outstandingCount' => $open->count(),
            'warnings' => $this->generationWarnings(),
        ]);
    }

    /**
     * Tick a duty on somebody's behalf, or close a whole backlog.
     */
    public function complete(Request $request, RoutineOccurrence $occurrence): RedirectResponse
    {
        $data = $request->validate([
            'day' => ['nullable', 'string', 'max:10'],
            'all' => ['sometimes', 'boolean'],
        ]);

        $rows = $request->boolean('all')
            ? $this->backlogFor($occurrence)
            : collect([$occurrence]);

        $result = $this->completer->completeMany($rows, $request->user());

        return redirect()
            ->route('routines.checking', ['day' => $data['day'] ?? null])
            ->with('status', $result['done'] > 0
                ? 'Marked '.$result['done'].' '.str('duty')->plural($result['done']).' done.'
                : 'Already closed.');
    }

    public function skip(Request $request, RoutineOccurrence $occurrence): RedirectResponse
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
            'day' => ['nullable', 'string', 'max:10'],
        ]);

        $this->completer->skip($occurrence, $request->user(), $data['note']);

        return redirect()
            ->route('routines.checking', ['day' => $data['day'] ?? null])
            ->with('status', 'Skipped.');
    }

    /**
     * The open rows behind one duty, oldest first.
     */
    private function backlogFor(RoutineOccurrence $occurrence): Collection
    {
        return RoutineOccurrence::query()
            ->where('status', RoutineOccurrence::STATUS_OPEN)
            ->where('routine_id', $occurrence->routine_id)
            ->where('checkpoint_id', $occurrence->checkpoint_id)
            ->where('subject_type', $occurrence->subject_type)
            ->where('subject_id', $occurrence->subject_id)
            ->where('assigned_user_id', $occurrence->assigned_user_id)
            ->orderBy('due_on')
            ->get();
    }

    /**
     * Duties bucketed by who owes them.
     *
     * Shared duties have no assignee by design -- first doer wins -- so they
     * go in one "anyone" bucket at the top rather than being repeated under
     * every permitted person.
     *
     * @param  Collection<int, RoutineOccurrence>  $open
     * @return Collection<int, array<string, mixed>>
     */
    private function groupByPerson(Collection $open): Collection
    {
        $duties = RoutineDutyList::group($open);

        $shared = $duties->filter(fn (array $d) => $d['assigned_user'] === null)->values();
        $owned = $duties->filter(fn (array $d) => $d['assigned_user'] !== null);

        $groups = collect();

        if ($shared->isNotEmpty()) {
            $groups->push([
                'person' => null,
                'name' => 'Anyone on the team',
                // 'duties' stays the flat, per-account count ("12 to do"
                // means twelve accounts, not two routines); 'tasks' is what
                // actually renders -- one card per routine, accounts nested
                // as a checklist underneath. See RoutineDutyList::nest().
                'duties' => $shared,
                'tasks' => RoutineDutyList::nest($shared),
                'late' => $shared->where('is_overdue', true)->count(),
            ]);
        }

        foreach ($owned->groupBy(fn (array $d) => $d['assigned_user']->id) as $rows) {
            /** @var User $person */
            $person = $rows->first()['assigned_user'];

            $groups->push([
                'person' => $person,
                'name' => $person->name,
                'duties' => $rows->values(),
                'tasks' => RoutineDutyList::nest($rows->values()),
                'late' => $rows->where('is_overdue', true)->count(),
            ]);
        }

        // Most behind first -- that is who needs chasing.
        return $groups->sortByDesc('late')->values();
    }

    /**
     * Active routines that cannot generate, so a duty that has quietly
     * stopped appearing is visible on the screen where it would be missed.
     *
     * @return Collection<int, array{routine: Routine, warning: string}>
     */
    private function generationWarnings(): Collection
    {
        return Routine::query()
            ->active()
            ->with('subjects')
            ->orderBy('title')
            ->get()
            ->map(fn (Routine $routine) => [
                'routine' => $routine,
                'warning' => $routine->generationWarning(),
            ])
            ->filter(fn (array $row) => $row['warning'] !== null)
            ->values();
    }

    private function resolveDay(?string $value): Carbon
    {
        if (! $value) {
            return today();
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (Throwable) {
            return today();
        }
    }
}
