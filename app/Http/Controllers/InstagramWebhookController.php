<?php

namespace App\Http\Controllers;

use App\Models\InstagramSetting;
use App\Models\SocialAccount;
use App\Models\SocialDataDeletion;
use App\Models\SocialWebhookEvent;
use App\Services\Instagram\SignedRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The endpoint Instagram posts to. Two verbs, two entirely different jobs.
 *
 * GET is the subscription handshake, run once when somebody presses "Verify
 * and save" in the Meta dashboard. POST is every event afterwards.
 *
 * Neither is behind auth, and neither can be: Meta arrives as a stranger with
 * no cookie. GET proves itself with the verify token, POST with a signature
 * over the body -- see VerifyInstagramSignature, which POST does not reach
 * this class without.
 *
 * NOT to be confused with InstagramConnectionController::callback(), which is
 * where a signed-in person lands after authorising. Same product, different
 * URL, opposite authentication story.
 */
class InstagramWebhookController extends Controller
{
    /**
     * The subscription handshake.
     *
     * Meta sends hub.mode=subscribe with the verify token typed into the
     * dashboard and expects hub.challenge echoed back as a bare body. Not
     * JSON, not wrapped -- Meta compares the response to the challenge
     * exactly, and anything else fails with the dashboard saying only that the
     * callback URL or verify token could not be validated.
     */
    public function verify(Request $request): Response
    {
        $settings = InstagramSetting::current();

        /*
         * Read with underscores because PHP rewrites a dot in a query string
         * parameter name to an underscore before any framework sees it.
         * Asking for 'hub.mode' would instead look for a nested key under
         * 'hub', find nothing, and refuse every handshake.
         */
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode !== 'subscribe' || ! $settings->matchesVerifyToken(is_string($token) ? $token : null)) {
            Log::warning('Instagram webhook verification failed.', [
                'ip' => $request->ip(),
                'mode' => $mode,
            ]);

            return response('', 403);
        }

        $settings->forceFill(['webhook_verified_at' => now()])->save();

        Log::info('Instagram webhook verified by Meta.');

        return response((string) $challenge, 200)->header('Content-Type', 'text/plain');
    }

    /**
     * Every event after the handshake.
     *
     * Always answers 200 once the signature has passed. Meta retries anything
     * else with backoff and eventually disables the subscription, so a bug in
     * our parsing must not become Meta deciding the studio is unreachable.
     */
    public function receive(Request $request): Response
    {
        $payload = $request->json()->all();

        try {
            $stored = SocialWebhookEvent::ingest(SocialAccount::PLATFORM_INSTAGRAM, $payload);

            Log::info('Instagram webhook received.', [
                'object' => $payload['object'] ?? null,
                'stored' => $stored,
            ]);
        } catch (Throwable $e) {
            // The payload goes in the log with the error: a parse failure is
            // unfixable without the thing that failed to parse, and there is
            // no credential in an Instagram event body.
            Log::error('Instagram webhook could not be stored.', [
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);
        }

        return response('', 200);
    }

    /**
     * Somebody removed the app from their Instagram account.
     *
     * Meta POSTs a signed_request naming the user. The right response is to
     * stop using the connection immediately: the token we hold has already
     * been revoked on their side, and continuing to try would be both broken
     * and rude.
     *
     * The row itself is kept, as with a staff-side disconnect -- the client
     * may reconnect, and the history is theirs either way.
     */
    public function deauthorize(Request $request): Response
    {
        $user = $this->signedUser($request);

        if ($user === null) {
            return response('', 403);
        }

        $account = $this->accountFor($user);

        if ($account) {
            $account->forceFill([
                'access_token' => null,
                'token_expires_at' => null,
                'status' => SocialAccount::STATUS_REVOKED,
                'last_error' => 'Access was removed from the Instagram account.',
                'last_error_at' => now(),
            ])->save();

            Log::info('Instagram access revoked by the account holder.', [
                'client_id' => $account->client_id,
            ]);
        }

        // 200 regardless of whether we knew the account: Meta is reporting a
        // fact, not asking a question, and a 404 here just earns retries.
        return response('', 200);
    }

    /**
     * A data deletion request, which Meta requires an app to honour.
     *
     * Answers with the JSON shape Meta specifies -- a status URL and a
     * confirmation code -- and that URL has to keep working afterwards, which
     * is why a receipt row outlives the data.
     *
     * What is deleted is what we hold *about the Instagram account*: the
     * token, the handle, the profile details. The client record, their
     * invoices and their brief are the studio's own business records and are
     * not Instagram's to ask for.
     */
    public function dataDeletion(Request $request): Response
    {
        $user = $this->signedUser($request);

        if ($user === null) {
            return response()->json(['error' => 'Invalid signed request.'], 403);
        }

        $receipt = SocialDataDeletion::open(SocialAccount::PLATFORM_INSTAGRAM, $user);
        $account = $this->accountFor($user);

        if ($account) {
            $account->socialWebhookEvents()->delete();

            $account->forceFill([
                'access_token' => null,
                'token_expires_at' => null,
                'username' => null,
                'account_type' => null,
                'profile_picture_url' => null,
                'followers_count' => null,
                'scopes' => null,
                'status' => SocialAccount::STATUS_REVOKED,
                'last_error' => 'Data deleted at the account holder\'s request.',
                'last_error_at' => now(),
            ])->save();

            $receipt->complete('Instagram account data deleted; the connection was closed.');
        } else {
            $receipt->complete('No Instagram data was held for that account.');
        }

        Log::info('Instagram data deletion honoured.', ['code' => $receipt->confirmation_code]);

        return response()->json([
            'url' => $receipt->statusUrl(),
            'confirmation_code' => $receipt->confirmation_code,
        ]);
    }

    /**
     * Where a person checks what happened to their request.
     *
     * Public and unauthenticated on purpose: the person asking has no account
     * here, which is rather the point of having asked.
     */
    public function deletionStatus(Request $request): Response
    {
        $receipt = SocialDataDeletion::where('confirmation_code', $request->query('code'))->first();

        return response()->view('instagram.deletion-status', ['receipt' => $receipt]);
    }

    /**
     * The Instagram user id from a verified signed_request, or null.
     *
     * Null covers both "not signed by us" and "we have no secret to check
     * against" -- in either case the only safe answer is to refuse.
     */
    private function signedUser(Request $request): ?string
    {
        $secret = InstagramSetting::current()->app_secret;

        if (blank($secret)) {
            Log::warning('Instagram signed request rejected: no app secret configured.');

            return null;
        }

        $payload = SignedRequest::parse($request->input('signed_request'), $secret);

        $user = $payload['user_id'] ?? $payload['user']['id'] ?? null;

        return $user ? (string) $user : null;
    }

    private function accountFor(string $platformUserId): ?SocialAccount
    {
        return SocialAccount::query()
            ->forPlatform(SocialAccount::PLATFORM_INSTAGRAM)
            ->where('platform_user_id', $platformUserId)
            ->first();
    }
}
