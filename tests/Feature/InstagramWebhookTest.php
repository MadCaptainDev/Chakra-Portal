<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\InstagramSetting;
use App\Models\SocialAccount;
use App\Models\SocialWebhookEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Instagram webhook.
 *
 * Public by necessity -- Meta verifies it as an anonymous stranger -- so, like
 * the WhatsApp one, most of this is about what it refuses.
 */
class InstagramWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'instagram-app-secret';

    private function configured(): InstagramSetting
    {
        $settings = InstagramSetting::current();
        $settings->update(['app_id' => '1082611097456947', 'app_secret' => self::SECRET]);

        return $settings->fresh();
    }

    /** @param array<string, mixed> $payload */
    private function postWebhook(array $payload, ?string $secret = self::SECRET): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload);

        $headers = $secret === null ? [] : [
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $secret),
        ];

        return $this->call('POST', '/webhooks/instagram', [], [], [],
            $this->transformHeadersToServerVars($headers + ['CONTENT_TYPE' => 'application/json']),
            $body);
    }

    /** @return array<string, mixed> */
    private function commentPayload(string $id = 'comment-1'): array
    {
        return [
            'object' => 'instagram',
            'entry' => [[
                'id' => '17841400000000001',
                'time' => 1755259200,
                'changes' => [[
                    'field' => 'comments',
                    'value' => ['id' => $id, 'text' => 'Loved this reel!'],
                ]],
            ]],
        ];
    }

    public function test_meta_can_complete_the_subscription_handshake(): void
    {
        $settings = $this->configured();

        $response = $this->get('/webhooks/instagram?hub.mode=subscribe'
            .'&hub.verify_token='.$settings->verify_token
            .'&hub.challenge=1158201444');

        // The body must be the bare challenge -- Meta compares it byte for byte.
        $response->assertOk();
        $this->assertSame('1158201444', $response->getContent());
        $this->assertNotNull($settings->fresh()->webhook_verified_at);
    }

    public function test_the_handshake_is_refused_with_the_wrong_verify_token(): void
    {
        $this->configured();

        $this->get('/webhooks/instagram?hub.mode=subscribe&hub.verify_token=guessed&hub.challenge=1')
            ->assertForbidden();

        $this->assertNull(InstagramSetting::current()->webhook_verified_at);
    }

    public function test_the_handshake_needs_no_login(): void
    {
        $settings = $this->configured();

        // The whole point: Meta arrives with no cookie. If this ever redirects
        // to /login, the OAuth callback has been pasted into the webhook field.
        $this->get('/webhooks/instagram?hub.mode=subscribe'
            .'&hub.verify_token='.$settings->verify_token.'&hub.challenge=99')
            ->assertOk()
            ->assertSee('99', escape: false);
    }

    public function test_a_signed_event_is_stored(): void
    {
        $this->configured();

        $this->postWebhook($this->commentPayload())->assertOk();

        $event = SocialWebhookEvent::sole();

        $this->assertSame('instagram', $event->platform);
        $this->assertSame('comments', $event->field);
        $this->assertSame('17841400000000001', $event->external_id);
    }

    public function test_an_unsigned_post_is_refused(): void
    {
        $this->configured();

        $this->postWebhook($this->commentPayload(), secret: null)->assertForbidden();

        $this->assertSame(0, SocialWebhookEvent::count());
    }

    public function test_a_post_signed_with_the_wrong_secret_is_refused(): void
    {
        $this->configured();

        $this->postWebhook($this->commentPayload(), secret: 'not-the-secret')->assertForbidden();

        $this->assertSame(0, SocialWebhookEvent::count());
    }

    public function test_nothing_is_accepted_until_an_app_secret_is_configured(): void
    {
        // Fails closed: with no secret there is no way to tell Meta from
        // anybody else who has learned the URL.
        $this->postWebhook($this->commentPayload())->assertForbidden();

        $this->assertSame(0, SocialWebhookEvent::count());
    }

    public function test_a_redelivered_event_does_not_become_a_second_row(): void
    {
        $this->configured();

        // Meta resends anything it does not get a prompt 200 for.
        $this->postWebhook($this->commentPayload())->assertOk();
        $this->postWebhook($this->commentPayload())->assertOk();

        $this->assertSame(1, SocialWebhookEvent::count());
    }

    public function test_rotating_the_verify_token_kills_the_old_one(): void
    {
        $settings = $this->configured();
        $old = $settings->verify_token;

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->post(route('instagram-settings.rotate'))
            ->assertRedirect(route('instagram-settings.edit'));

        $this->assertNotSame($old, InstagramSetting::current()->verify_token);

        $this->get('/webhooks/instagram?hub.mode=subscribe&hub.verify_token='.$old.'&hub.challenge=1')
            ->assertForbidden();
    }

    public function test_the_settings_screen_shows_both_urls_and_the_token(): void
    {
        $settings = $this->configured();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->get(route('instagram-settings.edit'))
            ->assertOk()
            ->assertSee($settings->callbackUrl())
            ->assertSee($settings->webhookUrl())
            ->assertSee($settings->verify_token);

        // They are genuinely different URLs -- swapping them is the failure
        // this screen is laid out to prevent.
        $this->assertNotSame($settings->callbackUrl(), $settings->webhookUrl());
    }
    /** Meta's signed_request: {base64url sig}.{base64url payload}. */
    private function signedRequest(array $payload, string $secret = self::SECRET): string
    {
        $encoded = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $encoded, $secret, true);

        return rtrim(strtr(base64_encode($signature), '+/', '-_'), '=').'.'.$encoded;
    }

    private function connectedAccount(string $userId = '17841400000000001'): SocialAccount
    {
        $client = Client::create(['name' => 'The Chakra Productions']);

        $account = SocialAccount::create([
            'client_id' => $client->id,
            'platform' => SocialAccount::PLATFORM_INSTAGRAM,
            'platform_user_id' => $userId,
            'username' => 'thechakra_productions',
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);

        $account->forceFill(['access_token' => 'IGQV-long-lived', 'connected_at' => now()])->save();

        return $account->fresh();
    }

    public function test_deauthorizing_stops_us_using_the_connection(): void
    {
        $this->configured();
        $account = $this->connectedAccount();

        $this->post('/webhooks/instagram/deauthorize', [
            'signed_request' => $this->signedRequest(['user_id' => '17841400000000001']),
        ])->assertOk();

        $account = $account->fresh();

        $this->assertSame(SocialAccount::STATUS_REVOKED, $account->status);
        $this->assertNull($account->access_token);
        // The record survives: they may reconnect, and the history is theirs.
        $this->assertSame('thechakra_productions', $account->username);
    }

    public function test_an_unsigned_deauthorize_is_refused(): void
    {
        $this->configured();
        $account = $this->connectedAccount();

        $this->post('/webhooks/instagram/deauthorize', [
            'signed_request' => $this->signedRequest(['user_id' => '17841400000000001'], 'wrong-secret'),
        ])->assertForbidden();

        $this->assertTrue($account->fresh()->isConnected());
    }

    public function test_a_data_deletion_request_is_honoured_and_answered(): void
    {
        $this->configured();
        $account = $this->connectedAccount();

        $response = $this->post('/webhooks/instagram/data-deletion', [
            'signed_request' => $this->signedRequest(['user_id' => '17841400000000001']),
        ])->assertOk();

        // Meta requires exactly this shape.
        $code = $response->json('confirmation_code');
        $this->assertNotEmpty($code);
        $this->assertStringContainsString($code, $response->json('url'));

        $account = $account->fresh();

        $this->assertNull($account->access_token);
        $this->assertNull($account->username);
        $this->assertSame(SocialAccount::STATUS_REVOKED, $account->status);
    }

    public function test_the_deletion_status_page_still_answers_after_the_data_is_gone(): void
    {
        $this->configured();
        $this->connectedAccount();

        $code = $this->post('/webhooks/instagram/data-deletion', [
            'signed_request' => $this->signedRequest(['user_id' => '17841400000000001']),
        ])->json('confirmation_code');

        // Public: the person asking has no account here, which is the point.
        $this->get(route('instagram.deletion-status', ['code' => $code]))
            ->assertOk()
            ->assertSee('deleted', escape: false);
    }

    public function test_an_unknown_confirmation_code_says_so(): void
    {
        $this->get(route('instagram.deletion-status', ['code' => 'nope']))
            ->assertOk()
            ->assertSee('could not find', escape: false);
    }

    public function test_the_authorize_url_forces_a_fresh_login(): void
    {
        $this->configured();

        $url = \App\Services\Instagram\InstagramOAuth::make()->authorizationUrl('state-value');

        // Without this, a staff member already signed in to the studio's own
        // Instagram would silently connect that account to the client.
        $this->assertStringContainsString('force_reauth=true', $url);
    }
}
