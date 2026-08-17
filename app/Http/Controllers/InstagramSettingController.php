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
}
