<?php

namespace App\Http\Controllers\My;

use App\Http\Controllers\Controller;
use App\Models\TimesheetDay;
use App\Models\TimesheetEntry;
use App\Support\TimesheetAnomalies;
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

        /*
         * What looks wrong in their own timesheet, this month. Scoped to them
         * inside the query, never filtered afterwards -- an employee must not
         * be one bug away from reading a colleague's rows.
         *
         * Shown to the person who wrote the entries rather than only to an
         * admin, because they are the only one who knows what actually
         * happened that day. An admin correcting a guess is worse than the
         * wrong number.
         */
        $flags = TimesheetAnomalies::between(
            $month->copy()->startOfMonth(),
            $month->copy()->endOfMonth(),
            $request->user()
        );

        return view('my.timesheet', [
            'month' => $month,
            'entries' => $entries,
            'flags' => $flags,
            // Anything older that still needs a look, so a month with a clean
            // panel does not read as "nothing to do" when there is.
            'olderFlagCount' => TimesheetAnomalies::fixableCountFor(
                $request->user(),
                $month->copy()->startOfMonth()->subMonthsNoOverflow(6),
                $month->copy()->startOfMonth()->subDay()
            ),
            // Keyed by date alone -- there is only one person on this screen.
            'decisions' => TimesheetDay::decisionsFor([$request->user()->id], $month)
                ->keyBy(fn (TimesheetDay $day) => $day->worked_on->toDateString()),
            'totalMinutes' => $entries->where('status', '!=', TimesheetEntry::STATUS_CANCELLED)->sum('minutes'),
            'ventureOptions' => TimesheetStats::ventureOptions(),
            'stats' => TimesheetStats::forEntries($entries, $month),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['user_id'] = $request->user()->id;

        $entry = new TimesheetEntry($data);

        /*
         * Whether this was filed late. Stamped here rather than derived later,
         * because "was this late?" is a fact about the moment it was written --
         * computed tomorrow, every entry in the system would look late.
         */
        $entry->was_backdated = TimesheetEntry::isLateFor($data['worked_on']);
        $entry->save();

        // Adding to a day a manager has already decided reopens it. They signed
        // off what the day said then, not what it says now.
        $this->reopenDays($entry->user_id, [$entry->worked_on]);

        return redirect()
            ->route('my.timesheet', ['month' => Carbon::parse($data['worked_on'])->format('Y-m')])
            ->with('status', $entry->was_backdated
                ? 'Entry added — it is for an earlier day, so your manager will see it flagged as late.'
                : 'Entry added.');
    }

    public function update(Request $request, TimesheetEntry $entry): RedirectResponse
    {
        $this->authoriseOwnership($request, $entry);

        $entry->fill($this->validated($request));

        /*
         * A real edit sends the whole day back to the manager. The day they
         * decided on is not the day this now describes, so their decision is
         * withdrawn rather than left standing over changed work.
         */
        if ($entry->isDirty()) {
            // Both days, because moving an entry to another date changes what
            // two days say, not one.
            $this->reopenDays($entry->user_id, [$entry->worked_on, $entry->getOriginal('worked_on')]);
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

        $this->reopenDays($entry->user_id, [$entry->worked_on]);

        return redirect()->route('my.timesheet', ['month' => $month])->with('status', 'Entry deleted.');
    }

    /**
     * Withdraw a manager's decision on the given days.
     *
     * whereDate rather than a plain equality: worked_on is a DATE column that
     * the model casts, so the value Eloquent writes carries a midnight time
     * under SQLite and not under MySQL. Comparing the date part matches on both.
     *
     * @param  array<int, mixed>  $days
     */
    private function reopenDays(int $userId, array $days): void
    {
        $dates = collect($days)
            ->filter()
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->unique();

        foreach ($dates as $date) {
            TimesheetDay::where('user_id', $userId)->whereDate('worked_on', $date)->delete();
        }
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
