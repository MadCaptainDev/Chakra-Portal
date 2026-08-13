<?php

namespace App\Http\Controllers;

use App\Models\EmployeePoint;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Support\TeamPulse;
use App\Support\TimesheetStats;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
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
            'queriedCount' => TimesheetEntry::forMonth($month)
                ->whereIn('user_id', $employees->pluck('id'))
                ->whereNotNull('review_note')
                ->count(),
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

    /**
     * Sign an entry off. Approving also settles the status: a manager saying
     * "yes, that happened" is the answer to the employee's "pending".
     */
    public function approve(Request $request, TimesheetEntry $entry): RedirectResponse
    {
        $this->markReviewed($request, $entry, note: null);

        $entry->status = TimesheetEntry::STATUS_COMPLETED;
        $entry->save();

        return $this->backToEntry($entry, 'Entry approved.');
    }

    /**
     * Ask the employee something about an entry. The status is left alone --
     * it is the employee's to set, and they may well answer by editing the
     * entry, which clears the question.
     */
    public function query(Request $request, TimesheetEntry $entry): RedirectResponse
    {
        $validated = $request->validate([
            'review_note' => ['required', 'string', 'max:1000'],
        ]);

        $this->markReviewed($request, $entry, note: $validated['review_note'], state: TimesheetEntry::REVIEW_QUERIED);
        $entry->save();

        return $this->backToEntry($entry, 'Question sent to '.Str::before($entry->user->name, ' ').'.');
    }

    /**
     * Clear the whole month's pending pile in one go -- the common case at the
     * end of a month, when a manager has read down the list and is happy.
     * Queried entries are skipped: an open question is not a rubber stamp.
     */
    public function approveMonth(Request $request, User $employee): RedirectResponse
    {
        abort_unless($employee->isEmployee(), 404);

        $month = $this->resolveMonth($request->input('month'));

        $entries = TimesheetEntry::where('user_id', $employee->id)
            ->forMonth($month)
            ->where('status', TimesheetEntry::STATUS_PENDING)
            ->whereNull('review_note')
            ->get();

        foreach ($entries as $entry) {
            $this->markReviewed($request, $entry, note: null);
            $entry->status = TimesheetEntry::STATUS_COMPLETED;
            $entry->save();
        }

        $count = $entries->count();

        return redirect()
            ->route('timesheets.show', [$employee, 'month' => $month->format('Y-m')])
            ->with('status', $count === 0
                ? 'Nothing was waiting to be approved.'
                : $count.' '.Str::plural('entry', $count).' approved.');
    }

    /**
     * The review columns are not fillable, so they are set here by hand -- the
     * one place in the app allowed to write them.
     */
    private function markReviewed(Request $request, TimesheetEntry $entry, ?string $note, string $state = TimesheetEntry::REVIEW_APPROVED): void
    {
        /*
         * Only this person's manager, or an admin, may decide. Checked here
         * rather than in the route because the answer depends on whose entry it
         * is, which a middleware cannot see.
         */
        abort_unless($request->user()->managesTimesheetOf($entry->user), 403);

        $entry->forceFill([
            'reviewed_at' => now(),
            'reviewed_by_id' => $request->user()->id,
            'review_note' => $note,
            'review_state' => $state,
        ]);
    }

    /**
     * Reject an entry, with a reason.
     *
     * The reason is required: "rejected" on its own tells somebody their work
     * was refused and nothing about what to do next, which is how a review
     * system becomes something people resent rather than use.
     */
    public function reject(Request $request, TimesheetEntry $entry): RedirectResponse
    {
        $validated = $request->validate([
            'review_note' => ['required', 'string', 'max:1000'],
        ]);

        $this->markReviewed($request, $entry, note: $validated['review_note'], state: TimesheetEntry::REVIEW_REJECTED);
        $entry->save();

        return $this->backToEntry($entry, 'Entry rejected — '.Str::before($entry->user->name, ' ').' has been told why.');
    }

    private function backToEntry(TimesheetEntry $entry, string $status): RedirectResponse
    {
        return redirect()
            ->route('timesheets.show', [$entry->user_id, 'month' => $entry->worked_on->format('Y-m')])
            ->with('status', $status);
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
