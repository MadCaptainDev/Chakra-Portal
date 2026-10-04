<?php

namespace App\Http\Controllers;

use App\Models\AdReport;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A month of paid-ads results on its no-login link, results/{token}.
 *
 * Same rule as the proposal's p/{token}: the token is the only credential,
 * and an unknown or switched-off one is a 404 -- never a hint that a report
 * exists at that address.
 */
class PublicAdReportController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $report = AdReport::with('client')->where('public_token', $token)->first();

        abort_if($report === null, 404);

        // "Viewed" means the client opened it. Staff checking the link before
        // sending it on are signed in and not a client, so they do not count
        // -- the confusion this avoids happened twice with proposals.
        $user = $request->user();

        if ($user === null || $user->isClient()) {
            $report->markViewed();
        }

        return view('ad-reports.public', ['report' => $report]);
    }
}
