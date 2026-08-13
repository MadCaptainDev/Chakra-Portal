<?php

namespace App\Http\Controllers;

use App\Support\BrowserSessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Signing a device out from the profile screen.
 *
 * No password confirmation. Signing out is the defensive move -- somebody who
 * has just realised their phone is gone should not be made to remember a
 * password first, and the worst a hijacked session can do through here is log
 * the real owner out, which tells them something is wrong.
 */
class BrowserSessionController extends Controller
{
    public function destroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'handle' => ['required', 'string', 'size:64'],
        ]);

        /*
         * Signing out the device you are holding is the Log out button, not
         * this one. Refused rather than allowed silently, because a session
         * that deletes its own row leaves the request half signed-in.
         */
        if (BrowserSessions::matches($validated['handle'], $request->session()->getId())) {
            return back()->with('error', 'That is this device — use Log out instead.');
        }

        $label = BrowserSessions::forget($request->user(), $validated['handle']);

        return back()->with(
            'status',
            $label === null
                ? 'That device was already signed out.'
                : $label.' was signed out.'
        );
    }

    public function destroyOthers(Request $request): RedirectResponse
    {
        $count = BrowserSessions::forgetOthers($request->user(), $request->session()->getId());

        return back()->with(
            'status',
            $count === 0
                ? 'You were not signed in anywhere else.'
                : $count.' other '.Str::plural('device', $count).' signed out.'
        );
    }
}
