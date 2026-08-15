<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ResolvesClient;
use App\Http\Controllers\Controller;
use App\Models\Shoot;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Shoots booked for this client.
 *
 * Date, time, location and status -- and nothing else. Crew names, kit lists
 * and the shoot's `notes` column are all internal: notes in particular is where
 * somebody writes "client always runs late, budget an extra hour". The columns
 * are named explicitly in the select for that reason, so a future column cannot
 * arrive on this screen by being added to the table.
 */
class ShootController extends Controller
{
    use ResolvesClient;

    public function index(Request $request): View
    {
        $client = $this->client($request);

        $upcoming = Shoot::where('client_id', $client->id)
            ->upcoming()
            ->ordered()
            ->get(['id', 'title', 'starts_at', 'ends_at', 'location', 'status']);

        /*
         * A short tail of what has already happened. Without it the screen is
         * empty whenever nothing is booked, which reads as "they have stopped
         * working for us" rather than "nothing is scheduled this week".
         */
        $past = Shoot::where('client_id', $client->id)
            ->where('starts_at', '<', now())
            ->orderByDesc('starts_at')
            ->limit(5)
            ->get(['id', 'title', 'starts_at', 'ends_at', 'location', 'status']);

        return view('client.shoots', [
            'client' => $client,
            'upcoming' => $upcoming,
            'past' => $past,
        ]);
    }
}
