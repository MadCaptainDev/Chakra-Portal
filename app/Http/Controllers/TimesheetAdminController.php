<?php

namespace App\Http\Controllers;

use App\Models\EmployeePoint;
use App\Models\TimesheetDay;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Support\TeamPulse;
use App\Support\TimesheetStats;
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

        $allEntries = TimesheetEntry::forMonth($month)
            ->whereIn('user_id', $employees->pluck('id'))
            ->get();

        $entries = $allEntries->groupBy('user_id');

        $points = EmployeePoint::whereDate('period', $month->toDateString())
            ->get()
            ->keyBy('user_id');

        $decisions = TimesheetDay::decisionsFor($employees->pluck('id'), $month);

        $rows = $employees->map(function (User $employee) use ($entries, $points, $decisions) {
            $own = $entries->get($employee->id, collect());
            $counted = $own->where('status', '!=', TimesheetEntry::STATUS_CANCELLED);
            $workedDays = $counted->pluck('worked_on')->map->toDateString()->unique();

            return [
                'employee' => $employee,
                'minutes' => $counted->sum('minutes'),
                'entries' => $own->count(),
                // Days with work on them that nobody has decided yet.
                'waiting' => $workedDays
                    ->reject(fn (string $date) => $decisions->has($employee->id.'|'.$date))
                    ->count(),
                'days' => $workedDays->count(),
                'point' => $points->get($employee->id),
            ];
        });

        $ranking = $rows
            ->filter(fn (array $row) => $row['minutes'] > 0)
            ->sortByDesc('minutes')
            ->values()
            ->map(fn (array $row) => [
                'label' => $row['employee']->name,
                'minutes' => (int) $row['minutes'],
            ])
            ->all();

        $teamStats = TimesheetStats::forEntries($allEntries, $month);

        return view('timesheets.index', [
            'month' => $month,
            'rows' => $rows,
            'totalMinutes' => $rows->sum('minutes'),
            'ranking' => $ranking,
            'teamStats' => $teamStats,
            'behind' => TeamPulse::behind($employees),
            'rejectedCount' => $decisions->where('review_state', TimesheetDay::REJECTED)->count(),
        ]);
    }

    public function show(Request $request, User $employee): View
    {
        abort_unless($employee->isEmployee(), 404);

        $month = $this->resolveMonth($request->query('month'));

        $entries = TimesheetEntry::where('user_id', $employee->id)
            ->forMonth($month)
            ->orderByDesc('worked_on')
            ->orderByRaw('started_at IS NULL')
            ->orderBy('started_at')
            ->get();

        return view('timesheets.show', [
            'month' => $month,
            'employee' => $employee,
            'entries' => $entries,
            'decisions' => TimesheetDay::decisionsFor([$employee->id], $month),
            'totalMinutes' => $entries->where('status', '!=', TimesheetEntry::STATUS_CANCELLED)->sum('minutes'),
            'point' => EmployeePoint::where('user_id', $employee->id)
                ->whereDate('period', $month->toDateString())
                ->first(),
            'stats' => TimesheetStats::forEntries($entries, $month),
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
