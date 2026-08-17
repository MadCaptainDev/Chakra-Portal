<?php

namespace App\Http\Controllers;

use App\Models\InstagramSetting;
use App\Models\SocialAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The studio's Instagram app credentials.
 *
 * Admin-only, beside Settings and WhatsApp. Connecting a particular client's
 * account is a different job and lives on that client's page; this is the one
 * app the whole studio connects through.
 */
class InstagramSettingController extends Controller
{
    public function edit(): View
    {
        return view('instagram.edit', [
            'settings' => InstagramSetting::current(),
            'connected' => SocialAccount::query()
                ->forPlatform(SocialAccount::PLATFORM_INSTAGRAM)
                ->connected()
                ->with('client')
                ->get(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'app_id' => ['nullable', 'string', 'max:64'],
            'app_secret' => ['nullable', 'string', 'max:255'],
            'sync_throttle_minutes' => [
                'required', 'integer',
                'min:'.InstagramSetting::MIN_SYNC_THROTTLE_MINUTES,
                'max:'.InstagramSetting::MAX_SYNC_THROTTLE_MINUTES,
            ],
        ]);

        /*
         * Blank means "leave it alone", not "clear it". The field cannot show
         * the current secret back -- it is a password input on a shared screen
         * -- so an empty submit is what saving the app id alone looks like,
         * and it must not silently break every connected account.
         */
        if (blank($validated['app_secret'] ?? null)) {
            unset($validated['app_secret']);
        }

        InstagramSetting::current()->update($validated + [
            'updated_by_id' => $request->user()->id,
        ]);

        return redirect()
            ->route('instagram-settings.edit')
            ->with('status', 'Instagram settings saved.');
    }

    /**
     * Issue a new webhook verify token.
     *
     * The old one dies immediately, so the subscription must be verified again
     * in the Meta dashboard. The flash says exactly that — a rotation that
     * looks successful here and silently breaks the dashboard is the worst
     * outcome this screen can have.
     */
    public function rotate(): RedirectResponse
    {
        InstagramSetting::current()->rotateVerifyToken();

        return redirect()->route('instagram-settings.edit')->with('status',
            'New verify token generated. Paste it into the Meta dashboard and press "Verify and save" again — until you do, the old token is dead.');
    }
}
