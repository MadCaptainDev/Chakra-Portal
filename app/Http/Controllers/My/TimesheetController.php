<?php

namespace App\Http\Controllers\My;

use App\Http\Controllers\Controller;
use App\Models\TimesheetEntry;
use App\Support\TimesheetStats;
use App\Support\TimesheetVenture;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * An employee's own timesheet.
 *
 * Every query is scoped to the signed-in user. Ownership is enforced here, in
 * the controller -- not merely by which links get rendered.
 */
class TimesheetController extends Controller
{
    public function index(Request $request): View
    {
        $month = $this->resolveMonth($request->query('month'));

        $entries = TimesheetEntry::where('user_id', $request->user()->id)
            ->forMonth($month)
            ->orderByDesc('worked_on')
            ->orderByRaw('started_at IS NULL')
            ->orderBy('started_at')
            ->get();

        return view('my.timesheet', [
            'month' => $month,
            'entries' => $entries,
            'totalMinutes' => $entries->where('status', '!=', TimesheetEntry::STATUS_CANCELLED)->sum('minutes'),
            'ventureOptions' => TimesheetStats::ventureOptions(),
            'stats' => TimesheetStats::forEntries($entries, $month),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['user_id'] = $request->user()->id;

        TimesheetEntry::create($data);

        return redirect()
            ->route('my.timesheet', ['month' => Carbon::parse($data['worked_on'])->format('Y-m')])
            ->with('status', 'Entry added.');
    }

    public function update(Request $request, TimesheetEntry $entry): RedirectResponse
    {
        $this->authoriseOwnership($request, $entry);

        $entry->fill($this->validated($request));

        // Editing answers a manager's question, or invalidates their approval:
        // either way the entry goes back into the unreviewed pile.
        if ($entry->isDirty() && $entry->reviewed_at !== null) {
            $entry->clearReview();
        }

        $entry->save();

        return redirect()
            ->route('my.timesheet', ['month' => $entry->worked_on->format('Y-m')])
            ->with('status', 'Entry updated.');
    }

    public function destroy(Request $request, TimesheetEntry $entry): RedirectResponse
    {
        $this->authoriseOwnership($request, $entry);

        $month = $entry->worked_on->format('Y-m');
        $entry->delete();

        return redirect()->route('my.timesheet', ['month' => $month])->with('status', 'Entry deleted.');
    }

    /**
     * 404 rather than 403: an employee has no business learning that another
     * person's entry exists.
     */
    private function authoriseOwnership(Request $request, TimesheetEntry $entry): void
    {
        abort_unless($entry->user_id === $request->user()->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'worked_on' => ['required', 'date'],
            'task' => ['required', 'string', 'max:255'],
            'task_type' => ['required', Rule::in(array_keys(TimesheetEntry::TASK_TYPES))],
            'venture' => TimesheetVenture::validationRules(),
            'started_at' => ['nullable', 'date_format:H:i'],
            'ended_at' => ['nullable', 'date_format:H:i'],
            'minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'status' => ['required', 'in:completed,pending,cancelled'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // Times win when both are given; otherwise keep whatever duration was
        // typed, since plenty of real entries have a duration but no end time.
        $derived = TimesheetEntry::minutesBetween($data['started_at'] ?? null, $data['ended_at'] ?? null);

        $data['minutes'] = $derived ?? (int) ($data['minutes'] ?? 0);
        $data['venture'] = TimesheetVenture::normalize(trim((string) $data['venture']))
            ?? trim((string) $data['venture']);
        $data['task_type'] = $data['task_type'] ?? TimesheetEntry::inferTaskType($data['task']);

        return $data;
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
