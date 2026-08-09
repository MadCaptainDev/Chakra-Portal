<?php

namespace App\Http\Controllers;

use App\Models\EmployeePoint;
use App\Models\TimesheetEntry;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Throwable;

/**
 * Admin view of everyone's timesheets, plus the monthly points award.
 */
class TimesheetAdminController extends Controller
{
    public function index(Request $request): View
    {
        $month = $this->resolveMonth($request->query('month'));

        $employees = User::where('role', User::ROLE_EMPLOYEE)->orderBy('name')->get();

        $entries = TimesheetEntry::forMonth($month)
            ->whereIn('user_id', $employees->pluck('id'))
            ->get()
            ->groupBy('user_id');

        $points = EmployeePoint::whereDate('period', $month->toDateString())
            ->get()
            ->keyBy('user_id');

        $rows = $employees->map(function (User $employee) use ($entries, $points) {
            $own = $entries->get($employee->id, collect());
            $counted = $own->where('status', '!=', TimesheetEntry::STATUS_CANCELLED);

            return [
                'employee' => $employee,
                'minutes' => $counted->sum('minutes'),
                'entries' => $own->count(),
                'pending' => $own->where('status', TimesheetEntry::STATUS_PENDING)->count(),
                'days' => $counted->pluck('worked_on')->map->toDateString()->unique()->count(),
                'point' => $points->get($employee->id),
            ];
        });

        // Busiest first: the point of this screen is spotting who logged what,
        // and a name-ordered list buries that. Ties fall back to the name so
        // the order is stable between months.
        $rows = $rows->sortBy([
            fn (array $a, array $b) => $b['minutes'] <=> $a['minutes'],
            fn (array $a, array $b) => $a['employee']->name <=> $b['employee']->name,
        ])->values();

        return view('timesheets.index', [
            'month' => $month,
            'rows' => $rows,
            'totalMinutes' => $rows->sum('minutes'),
            // Scale for the per-person bars, against a nominal 40-hour month so
            // one busy week does not make everyone else look idle.
            'peakMinutes' => max(2400, (int) $rows->max('minutes')),
        ]);
    }

    public function show(Request $request, User $employee): View
    {
        abort_unless($employee->isEmployee(), 404);

        $month = $this->resolveMonth($request->query('month'));

        $entries = TimesheetEntry::where('user_id', $employee->id)
            ->forMonth($month)
            ->orderByDesc('worked_on')
            ->orderBy('started_at')
            ->get();

        return view('timesheets.show', [
            'month' => $month,
            'employee' => $employee,
            'entries' => $entries,
            'totalMinutes' => $entries->where('status', '!=', TimesheetEntry::STATUS_CANCELLED)->sum('minutes'),
            'point' => EmployeePoint::where('user_id', $employee->id)
                ->whereDate('period', $month->toDateString())
                ->first(),
        ]);
    }

    /**
     * Award or update this employee's score for a month.
     */
    public function award(Request $request, User $employee): RedirectResponse
    {
        abort_unless($employee->isEmployee(), 404);

        $validated = $request->validate([
            'month' => ['required', 'date'],
            'points' => ['required', 'integer', 'min:0', 'max:1000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $period = Carbon::parse($validated['month'])->startOfMonth();

        EmployeePoint::updateOrCreate(
            ['user_id' => $employee->id, 'period' => $period->toDateString()],
            [
                'points' => $validated['points'],
                'note' => $validated['note'] ?? null,
                'awarded_by' => $request->user()->id,
            ]
        );

        return redirect()
            ->route('timesheets.show', [$employee, 'month' => $period->format('Y-m')])
            ->with('status', "Points recorded for {$employee->name}.");
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
