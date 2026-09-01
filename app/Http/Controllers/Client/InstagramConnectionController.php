<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ResolvesClient;
use App\Http\Controllers\Controller;
use App\Models\InstagramSetting;
use App\Models\SocialAccount;
use App\Services\Instagram\InstagramConnector;
use App\Services\Instagram\InstagramException;
use App\Services\Instagram\InstagramOAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Connecting your own Instagram account, self-service.
 *
 * The same OAuth dance as the staff-side
 * App\Http\Controllers\InstagramConnectionController (that one still exists,
 * for a coordinator connecting on behalf of a client with no portal login) --
 * see that class's own doc block for THE SECURITY DESIGN, which applies here
 * unchanged. The only real difference is where the client id comes from:
 * there, a route segment gated by clients,manage; here, the signed-in user's
 * own row via ResolvesClient, the same way every other client screen decides
 * whose data this request is about. Both write the identical session key
 * (InstagramOAuth::SESSION_KEY) and share the one callback route Meta's
 * single-redirect-URI limit allows.
 */
class InstagramConnectionController extends Controller
{
    use ResolvesClient;

    public function connect(Request $request): RedirectResponse
    {
        $client = $this->client($request);
        $settings = InstagramSetting::current();

        if (! $settings->isConfigured()) {
            return back()->with('status',
                'Instagram connection is not set up yet. Contact Chakra Groups.');
        }

        $state = InstagramOAuth::freshState();

        $request->session()->put(InstagramOAuth::SESSION_KEY, [
            'state' => $state,
            'client_id' => $client->id,
        ]);

        try {
            return redirect()->away(InstagramOAuth::make()->authorizationUrl($state));
        } catch (InstagramException $e) {
            return back()->with('status', $e->userMessage());
        }
    }

    public function destroy(Request $request): RedirectResponse
    {
        $client = $this->client($request);

        $account = $client->socialAccounts()
            ->forPlatform(SocialAccount::PLATFORM_INSTAGRAM)
            ->first();

        if (! $account) {
            return back()->with('status', 'There is no Instagram account connected.');
        }

        InstagramConnector::make()->disconnect($account);

        return back()->with('status',
            'Instagram disconnected. The stored token has been discarded; anything already collected is kept.');
    }
}
