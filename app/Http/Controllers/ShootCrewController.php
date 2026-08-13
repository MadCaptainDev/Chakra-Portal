<?php

namespace App\Http\Controllers;

use App\Models\Shoot;
use App\Models\ShootCrew;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
        // double-tapping.
        $shoot->crew()->updateOrCreate(
            ['user_id' => $validated['user_id']],
            ['role' => $validated['role'] ?? null, 'call_time' => $validated['call_time'] ?? null]
        );

        return redirect()->route('shoots.show', $shoot)->with('status', 'Crew updated.');
    }

    public function destroy(Shoot $shoot, ShootCrew $crew): RedirectResponse
    {
        $crew->delete();

        return redirect()->route('shoots.show', $shoot)->with('status', 'Removed from the crew.');
    }
}
