<?php

namespace App\Http\Controllers;

use App\Models\WhatsappSetting;
use App\Models\WhatsappWebhookEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The admin side of the WhatsApp connection.
 *
 * This screen exists so that setting the webhook up is a copy-and-paste job
 * from one page into the Meta dashboard, and so that "is it working" is a
 * question the screen answers rather than one somebody answers by reading the
 * server log over SSH.
 *
 * Admin-only, and not a permission module: connecting the studio's own Meta app
 * is not a job that gets delegated a piece at a time, and the app secret on this
 * page can send messages as the studio.
 */
class WhatsappSettingController extends Controller
{
    /** How many events the screen shows before it stops being a list. */
    private const RECENT_LIMIT = 25;

    public function edit(): View
    {
        return view('whatsapp.edit', [
            'settings' => WhatsappSetting::current(),
            'events' => WhatsappWebhookEvent::query()
                ->newestFirst()
                ->limit(self::RECENT_LIMIT)
                ->get(),
            'totalEvents' => WhatsappWebhookEvent::count(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            /*
             * Left blank means "leave it alone", not "clear it". The field
             * cannot show the current secret back -- it is a password input on
             * a shared screen -- so an empty submit is what saving any *other*
             * field on this form looks like, and it must not silently take the
             * endpoint offline.
             */
            'app_secret' => ['nullable', 'string', 'max:255'],
            'phone_number_id' => ['nullable', 'string', 'max:64'],
            'business_account_id' => ['nullable', 'string', 'max:64'],
            'display_phone_number' => ['nullable', 'string', 'max:32'],
        ]);

        if (blank($validated['app_secret'] ?? null)) {
            unset($validated['app_secret']);
        }

        WhatsappSetting::current()->update($validated + [
            'updated_by_id' => $request->user()->id,
        ]);

        return redirect()
            ->route('whatsapp.edit')
            ->with('status', 'WhatsApp settings saved.');
    }

    /**
     * Issue a new verify token.
     *
     * The old one stops working the moment this runs, so the subscription in
     * the Meta dashboard has to be re-verified with the new value. The flash
     * message says exactly that -- a rotation that looks successful here and
     * silently breaks the dashboard is the worst outcome this screen can have.
     */
    public function rotate(): RedirectResponse
    {
        WhatsappSetting::current()->rotateVerifyToken();

        return redirect()
            ->route('whatsapp.edit')
            ->with('status', 'New verify token generated. Paste it into the Meta dashboard and press "Verify and save" again — until you do, the old token is dead and Meta cannot re-verify this URL.');
    }
}
