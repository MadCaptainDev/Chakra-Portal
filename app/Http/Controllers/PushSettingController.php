<?php

namespace App\Http\Controllers;

use App\Models\PushSetting;
use App\Models\PushToken;
use App\Models\User;
use App\Services\Push\PushMessage;
use App\Services\Push\PushSender;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * The admin side of the Firebase push connection.
 *
 * Admin-only, and not a permission module -- same reasoning as WhatsApp and
 * Notion: connecting the studio's own Firebase project is not a job that
 * gets delegated a piece at a time, and the service account on this screen
 * can send a push as the studio to every registered device.
 */
class PushSettingController extends Controller
{
    public function edit(): View
    {
        $settings = PushSetting::current();

        return view('push.edit', [
            'settings' => $settings,
            'projectId' => $settings->projectId(),
            'clientEmail' => $settings->serviceAccount()['client_email'] ?? null,
            'projectsMatch' => $settings->isConfigured() ? $settings->projectsMatch() : null,
            'deviceCount' => PushToken::count(),
            'staffWithDevices' => User::staff()->whereHas('pushTokens')->count(),
            'totalStaff' => User::staff()->count(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // 8000: real service-account JSON runs ~2.3 KB; generous room
            // without inviting an unrelated paste.
            'service_account_json' => ['nullable', 'string', 'max:8000'],
            'web_config' => ['nullable', 'string', 'max:4000'],
            'vapid_public_key' => ['nullable', 'string', 'max:255'],
        ]);

        /*
         * Blank means "leave it alone" for the SECRET only -- the same rule
         * WhatsApp and Notion use, and for the same reason: a password
         * field cannot show the saved value back, so an empty submit is
         * what saving any other field on this form looks like. web_config
         * and vapid_public_key are NOT secrets (they render in the form in
         * plain text, visible below), so for them blank genuinely means
         * "clear this" -- the two fields deliberately behave differently,
         * and the view says so.
         */
        if (blank($validated['service_account_json'] ?? null)) {
            unset($validated['service_account_json']);
        } else {
            $decoded = json_decode($validated['service_account_json'], true);
            $missing = array_diff(['type', 'project_id', 'client_email', 'private_key'], array_keys($decoded ?? []));

            if (! is_array($decoded) || $missing !== []) {
                return back()->withInput()->withErrors([
                    'service_account_json' => $decoded === null
                        ? 'That is not valid JSON.'
                        : 'That JSON is missing: '.implode(', ', $missing).'. Paste the whole file Firebase downloaded.',
                ]);
            }
        }

        if (filled($validated['web_config'] ?? null) && json_decode($validated['web_config'], true) === null) {
            return back()->withInput()->withErrors(['web_config' => 'That is not valid JSON.']);
        }

        $settings = PushSetting::current();
        $settings->fill($validated + ['updated_by_id' => $request->user()->id]);

        /*
         * Checked on the merged state (existing + incoming), not just the
         * new value -- editing only the web config while a service account
         * already exists still has to be checked against it. This is the
         * single most common setup mistake: pasting the service account
         * from one Firebase project and the web config from another. Left
         * unchecked it does not fail here -- it fails twenty minutes later
         * as SENDER_ID_MISMATCH on somebody's phone.
         */
        if ($settings->isConfigured() && filled($settings->web_config) && ! $settings->projectsMatch()) {
            return back()->withInput()->withErrors([
                'web_config' => "This web config's projectId does not match the service account's project_id ({$settings->projectId()}). Both halves must come from the same Firebase project.",
            ]);
        }

        $settings->save();

        return redirect()->route('push.edit')->with('status', 'Push notification settings saved.');
    }

    /**
     * Send a real push to the admin doing this, so "it's connected" is
     * something the screen proves rather than something the admin assumes.
     *
     * Deliberately does NOT catch: an admin pressing this button wants
     * Google's own error, word for word, the same way whatsapp:send prints
     * Meta's. Two friendly checks come first because the two likeliest
     * failures here are not Google's fault at all.
     */
    public function test(Request $request): RedirectResponse
    {
        $tokens = $request->user()->routeNotificationForFcm();

        if ($tokens->isEmpty()) {
            return back()->with('status',
                "You haven't turned notifications on for this browser yet — do that on your profile first, then come back and try again.");
        }

        try {
            $result = PushSender::make()->send($tokens, new PushMessage(
                'Test notification',
                'Push notifications are working.',
                route('push.edit'),
            ));
        } catch (Throwable $e) {
            return back()->with('status', 'Could not send: '.$e->getMessage());
        }

        return back()->with('status', "Sent to {$result['sent']} device(s). If nothing arrived, check the browser actually allowed notifications for this site.");
    }
}
