<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ResolvesClient;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * One screen: is Instagram connected, and a button to change that.
 *
 * Deliberately its own page rather than a card bolted onto the dashboard --
 * DashboardController's own doc block is explicit that screen exists to
 * answer one of three questions and nothing else. Connecting a social account
 * is a task, on the same footing as the brief or a shoot, not a status to
 * skim past on the way to the balance due.
 */
class SocialController extends Controller
{
    use ResolvesClient;

    public function index(Request $request): View
    {
        return view('client.social', [
            'client' => $this->client($request),
        ]);
    }
}
