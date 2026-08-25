<?php

namespace App\Http\Controllers;

use App\Models\RoutineOccurrence;
use App\Services\RoutineCompleter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Throwable;

/**
 * Admin calendar of all routine occurrences for a month.
 */
class RoutineCalendarController extends Controller
{
    public function __construct(private readonly RoutineCompleter $completer) {}

    public function index(Request $request): View
    {
        // Generation is the routines.catchup middleware's job -- it claims the
        // day atomically and runs once. Calling it here ran a firstOrCreate per
        // routine x date x checkpoint x subject x user on every page view.
        $month = $this->resolveMonth($request->query('month'));

        $occurrences = RoutineOccurrence::query()
            ->with(['routine', 'checkpoint', 'subject', 'assignedUser', 'completedByUser'])
            ->whereDate('due_on', '>=', $month->copy()->startOfMonth()->toDateString())
            ->whereDate('due_on', '<=', $month->copy()->endOfMonth()->toDateString())
            ->orderBy('due_on')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (RoutineOccurrence $o) => $o->due_on->toDateString());

        return view('routines.calendar', [
            'month' => $month,
            'weeks' => $this->weeks($month, $occurrences),
            'occurrencesByDay' => $occurrences,
            'overdueCount' => RoutineOccurrence::overdueCount(),
        ]);
    }

    public function skip(Request $request, RoutineOccurrence $occurrence): RedirectResponse
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
            'month' => ['nullable', 'string', 'max:7'],
        ]);

        $this->completer->skip($occurrence, $request->user(), $data['note']);

        return redirect()
            ->route('routines.calendar', ['month' => $data['month'] ?? $occurrence->due_on->format('Y-m')])
            ->with('status', 'Skipped.');
    }

    /**
     * @param  Collection<string, Collection<int, RoutineOccurrence>>  $occurrences
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function weeks(Carbon $month, $occurrences): array
    {
        $start = $month->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY);
        $end = $month->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);

        $weeks = [];
        $days = [];
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $dayItems = $occurrences->get($key, collect());

            $days[] = [
                'date' => $cursor->copy(),
                'inMonth' => $cursor->month === $month->month,
                'isToday' => $cursor->isToday(),
                'occurrences' => $dayItems,
                'openCount' => $dayItems->where('status', RoutineOccurrence::STATUS_OPEN)->count(),
                'overdueCount' => $dayItems->filter(fn (RoutineOccurrence $o) => $o->isOverdue())->count(),
            ];

            if (count($days) === 7) {
                $weeks[] = $days;
                $days = [];
            }

            $cursor->addDay();
        }

        return $weeks;
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
