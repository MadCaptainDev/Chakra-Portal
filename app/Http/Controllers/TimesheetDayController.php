<?php

namespace App\Http\Controllers;

use App\Models\TimesheetDay;
use App\Models\User;
use App\Notifications\TimesheetDayRejected;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Accepting or rejecting a whole day of someone's timesheet.
 *
 * Who may decide depends on whose day it is -- that person's own manager, or
 * any admin. A per-row question, so it is checked here rather than in a
 * middleware that cannot see the row.
 */
class TimesheetDayController extends Controller
{
    public function store(Request $request, User $employee): RedirectResponse
    {
        abort_unless($request->user()->managesTimesheetOf($employee), 403);

        $validated = $request->validate([
            'worked_on' => ['required', 'date'],
            'review_state' => ['required', Rule::in(array_keys(TimesheetDay::STATES))],
            /*
             * A rejection has to say why. "Rejected" on its own tells somebody
             * their day was refused and nothing about what to do next, which is
             * how a review system turns into something people resent.
             */
            'review_note' => [
                Rule::requiredIf($request->input('review_state') === TimesheetDay::REJECTED),
                'nullable', 'string', 'max:1000',
            ],
        ]);

        $day = Carbon::parse($validated['worked_on'])->startOfDay();

        /*
         * whereDate, not a plain equality on the string. worked_on is a DATE
         * column but the model casts it, so Eloquent writes "2026-08-11
         * 00:00:00"; MySQL truncates that on the way in and SQLite does not.
         * Matching on the date part is the one form that finds the row on both.
         */
        $decision = TimesheetDay::where('user_id', $employee->id)
            ->whereDate('worked_on', $day->toDateString())
            ->first()
            ?? new TimesheetDay(['user_id' => $employee->id, 'worked_on' => $day->toDateString()]);

        // Deciding again replaces the earlier decision rather than stacking a
        // second one beside it -- a manager changing their mind is normal.
        $decision->review_state = $validated['review_state'];
        $decision->review_note = $validated['review_note'] ?? null;
        $decision->forceFill([
            'reviewed_at' => now(),
            'reviewed_by_id' => $request->user()->id,
        ])->save();

        if ($validated['review_state'] === TimesheetDay::REJECTED) {
            try {
                Notification::send($employee, new TimesheetDayRejected($decision));
            } catch (Throwable $e) {
                Log::error('Timesheet day rejection push failed.', ['timesheet_day_id' => $decision->id, 'error' => $e->getMessage()]);
            }
        }

        $verb = $validated['review_state'] === TimesheetDay::APPROVED ? 'approved' : 'rejected';

        return back()->with(
            'status',
            Str::before($employee->name, ' ').'\'s '.$day->format('D d M').' was '.$verb.'.'
        );
    }
}
