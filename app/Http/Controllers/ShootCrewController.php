<?php

namespace App\Http\Controllers;

use App\Models\Shoot;
use App\Models\ShootCrew;
use App\Notifications\ShootCrewAdded;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Throwable;

class ShootCrewController extends Controller
{
    public function store(Request $request, Shoot $shoot): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')],
            'role' => ['nullable', 'string', 'max:80'],
            'call_time' => ['nullable', 'date_format:H:i'],
        ]);

        // Adding the same person twice is a no-op rather than an error -- the
        // unique index would refuse it and the producer would get a 500 for
        // double-tapping. The return value used to be discarded; it is
        // captured now so wasRecentlyCreated can tell "added" from "edited".
        $crew = $shoot->crew()->updateOrCreate(
            ['user_id' => $validated['user_id']],
            ['role' => $validated['role'] ?? null, 'call_time' => $validated['call_time'] ?? null]
        );

        // Only a genuinely NEW crew row notifies -- editing somebody's call
        // time must not re-alert them. Also skipped: the producer crewing
        // themselves (nothing to tell them), and a shoot that is cancelled
        // or already in the past (nobody needs a call time for that).
        if ($crew->wasRecentlyCreated
            && $validated['user_id'] !== $request->user()->id
            && $shoot->status !== Shoot::STATUS_CANCELLED
            && ! $shoot->starts_at->isPast()) {
            try {
                Notification::send($crew->user, new ShootCrewAdded($crew));
            } catch (Throwable $e) {
                Log::error('Shoot crew push failed.', ['shoot_crew_id' => $crew->id, 'error' => $e->getMessage()]);
            }
        }

        return redirect()->route('shoots.show', $shoot)->with('status', 'Crew updated.');
    }

    public function destroy(Shoot $shoot, ShootCrew $crew): RedirectResponse
    {
        $crew->delete();

        return redirect()->route('shoots.show', $shoot)->with('status', 'Removed from the crew.');
    }
}
