<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ResolvesClient;
use App\Http\Controllers\Controller;
use App\Models\Shoot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A client asking for a shoot, instead of a WhatsApp back-and-forth about a
 * date. Lands as an ordinary Shoot -- status Planned, the lightest state
 * the staff board already has, so this needs no new status the Shoots
 * screen would have to learn to read -- but stamped with requested_at,
 * which the board uses to flag it for triage. Staff still confirm the
 * date, add a location if the client didn't give one that means anything
 * to a crew, and assign kit; this only starts that conversation, it does
 * not schedule anything on its own.
 */
class ShootRequestController extends Controller
{
    use ResolvesClient;

    public function store(Request $request): RedirectResponse
    {
        $client = $this->client($request);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date', 'after_or_equal:today'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        Shoot::create([
            'title' => $data['title'],
            'client_id' => $client->id,
            'starts_at' => $data['starts_at'],
            'location' => $data['location'] ?? null,
            'status' => Shoot::STATUS_PLANNED,
            'notes' => $data['notes'] ?? null,
            'created_by_id' => $request->user()->id,
            'requested_at' => now(),
        ]);

        return redirect()->route('client.shoots')
            ->with('status', 'Request sent — the studio will confirm the date with you.');
    }
}
