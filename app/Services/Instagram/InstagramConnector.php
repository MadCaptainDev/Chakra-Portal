<?php

namespace App\Services\Instagram;

use App\Models\Client;
use App\Models\InstagramSetting;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turning an authorization code into a stored connection.
 *
 * The one place that writes a social_accounts row for Instagram, so the rules
 * about what a connection is -- reconnect reuses, one account belongs to one
 * client, disconnect keeps the row -- are stated once.
 */
class InstagramConnector
{
    public function __construct(private readonly InstagramOAuth $oauth) {}

    public static function make(): self
    {
        return new self(InstagramOAuth::make());
    }

    /**
     * Complete the flow for one client.
     *
     * The client comes from the SESSION, never from the callback URL -- see
     * InstagramConnectionController. By the time this runs, whose account this
     * is has already been decided by the button that was pressed.
     */
    public function connect(Client $client, string $code, ?User $by = null): SocialAccount
    {
        $exchange = $this->oauth->exchangeCode($code);
        $account = $this->oauth->getAccount($exchange['token']);

        $platformUserId = (string) ($account['user_id'] ?? $exchange['user_id'] ?? '');

        if ($platformUserId === '') {
            throw new InstagramException('Instagram did not identify the account that authorised us.');
        }

        $this->refuseIfHeldByAnotherClient($client, $platformUserId);

        return DB::transaction(function () use ($client, $account, $exchange, $platformUserId, $by) {
            /*
             * updateOrCreate on (client, platform): reconnecting is the same
             * row again, so anything that comes to hang off it -- insights,
             * in Phase 3 -- stays attached rather than being orphaned by a
             * client re-authorising after a password change.
             */
            $social = SocialAccount::firstOrNew([
                'client_id' => $client->id,
                'platform' => SocialAccount::PLATFORM_INSTAGRAM,
            ]);

            $social->forceFill([
                'platform_user_id' => $platformUserId,
                'username' => $account['username'] ?? null,
                'account_type' => $account['account_type'] ?? null,
                'profile_picture_url' => $account['profile_picture_url'] ?? null,
                'followers_count' => $account['followers_count'] ?? null,
                'access_token' => $exchange['token'],
                'token_expires_at' => $exchange['expires_at'],
                'scopes' => $exchange['permissions'],
                'status' => SocialAccount::STATUS_CONNECTED,
                'connected_at' => now(),
                'connected_by_id' => $by?->id,
                'last_error' => null,
                'last_error_at' => null,
            ])->save();

            // Stamped on the first connection that completes, so the settings
            // screen can say the app itself works rather than leaving that to
            // the logs.
            InstagramSetting::current()->forceFill(['verified_at' => now()])->save();

            Log::info('Instagram account connected.', [
                'client_id' => $client->id,
                'username' => $social->username,
            ]);

            return $social;
        });
    }

    /**
     * Stop using the connection, without losing the record of it.
     *
     * The token is cleared -- there is no reason to keep a credential we have
     * promised not to use -- but the row stays, so reconnecting later is the
     * same account rather than a new one, and anything hanging off it survives.
     */
    public function disconnect(SocialAccount $account): void
    {
        $account->forceFill([
            'access_token' => null,
            'token_expires_at' => null,
            'status' => SocialAccount::STATUS_REVOKED,
        ])->save();

        Log::info('Instagram account disconnected.', [
            'client_id' => $account->client_id,
            'username' => $account->username,
        ]);
    }

    /**
     * One Instagram account belongs to one client.
     *
     * Without this, a mis-click during onboarding attaches the same account to
     * two clients and splits its analytics across both -- neither total right,
     * and nothing on either screen saying so. The unique index enforces it too;
     * this exists to fail with a sentence rather than a constraint violation.
     */
    private function refuseIfHeldByAnotherClient(Client $client, string $platformUserId): void
    {
        $existing = SocialAccount::query()
            ->forPlatform(SocialAccount::PLATFORM_INSTAGRAM)
            ->where('platform_user_id', $platformUserId)
            ->where('client_id', '!=', $client->id)
            ->with('client')
            ->first();

        if ($existing) {
            throw new InstagramException(sprintf(
                'That Instagram account is already connected to %s. Disconnect it there first.',
                $existing->client?->name ?? 'another client'
            ));
        }
    }
}
