<?php

namespace App\Http\Controllers\My;

use App\Http\Controllers\Controller;
use App\Models\RoutineOccurrence;
use App\Services\RoutineCompleter;
use App\Services\RoutineOccurrenceGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * An employee's own routine duties.
 *
 * Every query is scoped to routines they are permitted on (and, in
 * individual mode, rows assigned to them). Ownership is enforced here —
 * not merely by which links get rendered.
 */
class RoutineController extends Controller
{
    public function __construct(
        private readonly RoutineCompleter $completer,
        private readonly RoutineOccurrenceGenerator $generator,
    ) {}

    public function index(Request $request): View
    {
        // Catch up if the scheduler missed today (my/ sits outside routines.catchup).
        $this->generator->run();

        $user = $request->user();
        $today = today();

        $query = RoutineOccurrence::query()
            ->with(['routine.fields', 'checkpoint', 'subject', 'completedByUser'])
            ->where('status', RoutineOccurrence::STATUS_OPEN)
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

        $open = $query->get();

        return view('my.routines', [
            'overdue' => $open->filter(fn (RoutineOccurrence $o) => $o->due_on->lt($today)),
            'todayItems' => $open->filter(fn (RoutineOccurrence $o) => $o->due_on->isSameDay($today)),
            'upcoming' => $open->filter(fn (RoutineOccurrence $o) => $o->due_on->gt($today))->take(40),
            'today' => $today,
        ]);
    }

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
