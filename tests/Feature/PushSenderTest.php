<?php

namespace Tests\Feature;

use App\Models\PushSetting;
use App\Models\PushToken;
use App\Models\User;
use App\Services\Push\PushMessage;
use App\Services\Push\PushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Sending a push notification through Firebase's HTTP v1 API.
 *
 * The OAuth half is faked as a real JWT-bearer exchange: a real 2048-bit
 * keypair is generated once for the whole class (not committed as a
 * fixture -- a private key sitting in the repo needs a paragraph of
 * explanation a static property avoids entirely), and the service account
 * JSON built from it is what every test in this file configures.
 */
class PushSenderTest extends TestCase
{
    use RefreshDatabase;

    private static ?string $privateKeyPem = null;

    private static function privateKeyPem(): string
    {
        if (self::$privateKeyPem === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $pem);
            self::$privateKeyPem = $pem;
        }

        return self::$privateKeyPem;
    }

    private function configured(string $projectId = 'chakra-portal-test'): PushSetting
    {
        $settings = PushSetting::current();

        $settings->update([
            'service_account_json' => json_encode([
                'type' => 'service_account',
                'project_id' => $projectId,
                'client_email' => 'firebase-adminsdk@'.$projectId.'.iam.gserviceaccount.com',
                'private_key' => self::privateKeyPem(),
            ]),
            'web_config' => json_encode(['projectId' => $projectId]),
        ]);

        return $settings->fresh();
    }

    private function fakeOAuth(int $expiresIn = 3599): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'ya29.test-access-token',
                'expires_in' => $expiresIn,
                'token_type' => 'Bearer',
            ]),
        ]);
    }

    private function fakeFcm(array $body = [], int $status = 200): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'ya29.test-access-token',
                'expires_in' => 3599,
            ]),
            'fcm.googleapis.com/*' => Http::response($body, $status),
        ]);
    }

    private function tokenFor(User $user, string $token = 'fcm-token-abc'): PushToken
    {
        return PushToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'device_label' => 'Chrome on Windows',
            'device_kind' => 'desktop',
        ]);
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_the_message_is_data_only_in_the_shape_the_service_worker_expects(): void
    {
        $this->configured();
        $this->fakeFcm(['name' => 'projects/x/messages/1']);
        $user = $this->staff();
        $token = $this->tokenFor($user);

        PushSender::make()->send(collect([$token]), new PushMessage('New announcement', 'Studio closed Friday', '/announcements', 'announcement-1'));

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'fcm.googleapis.com')) {
                return true; // the OAuth call, not the one under test
            }

            $message = $request->data()['message'];

            $this->assertSame('fcm-token-abc', $message['token']);
            $this->assertSame('New announcement', $message['data']['title']);
            $this->assertSame('Studio closed Friday', $message['data']['body']);
            $this->assertSame('/announcements', $message['data']['url']);
            $this->assertSame('announcement-1', $message['data']['tag']);
            // Every data value is a string -- FCM refuses non-strings outright.
            foreach ($message['data'] as $value) {
                $this->assertIsString($value);
            }
            // No auto-display key anywhere -- the SW SDK is not loaded into
            // sw.js, so a `notification` key would arrive and display
            // nothing, and Chrome would show its own generic notification.
            $this->assertArrayNotHasKey('notification', $message);
            $this->assertArrayNotHasKey('notification', $message['webpush']);
            $this->assertSame('86400', $message['webpush']['headers']['TTL']);

            return true;
        });
    }

    public function test_the_access_token_is_minted_once_and_reused(): void
    {
        $this->configured();
        $this->fakeFcm(['name' => 'x']);
        $user = $this->staff();
        $token = $this->tokenFor($user);
        $sender = PushSender::make();

        $sender->send(collect([$token]), new PushMessage('One', 'First send'));
        $sender->send(collect([$token]), new PushMessage('Two', 'Second send'));

        // assertSentCount() takes no filter, so count the oauth calls directly.
        $this->assertCount(1, Http::recorded(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com')));
    }

    public function test_googles_error_is_reported_verbatim(): void
    {
        $this->configured();
        // canSend() succeeds (the service account JSON itself is valid) --
        // this tests the OAuth exchange itself being refused by Google,
        // which is the one failure PushSender::send() lets propagate,
        // since nothing could be attempted without a token.
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'Invalid JWT Signature.',
            ], 400),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid JWT Signature.');

        PushSender::make()->send(collect([$this->tokenFor($this->staff())]), new PushMessage('T', 'B'));
    }

    public function test_an_unregistered_token_is_pruned(): void
    {
        $this->configured();
        $this->fakeFcm([
            'error' => ['message' => 'Requested entity was not found.', 'details' => [
                ['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED'],
            ]],
        ], status: 404);
        $token = $this->tokenFor($this->staff());

        $result = PushSender::make()->send(collect([$token]), new PushMessage('T', 'B'));

        $this->assertSame(['sent' => 0, 'pruned' => 1, 'failed' => 0], $result);
        $this->assertDatabaseMissing('push_tokens', ['id' => $token->id]);
    }

    public function test_a_transient_failure_keeps_the_token(): void
    {
        $this->configured();
        $this->fakeFcm([
            'error' => ['message' => 'The service is currently unavailable.', 'details' => [
                ['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNAVAILABLE'],
            ]],
        ], status: 503);
        $token = $this->tokenFor($this->staff());

        $result = PushSender::make()->send(collect([$token]), new PushMessage('T', 'B'));

        $this->assertSame(['sent' => 0, 'pruned' => 0, 'failed' => 1], $result);
        $this->assertDatabaseHas('push_tokens', ['id' => $token->id]);
        $this->assertNotNull($token->fresh()->last_failed_at);
    }

    public function test_a_bad_payload_does_not_delete_every_device(): void
    {
        // A 400 INVALID_ARGUMENT that does NOT name message.token -- e.g. a
        // malformed data value on our side. Treating every 400 as a dead
        // token would delete every device in the studio on the first
        // announcement after a bad deploy.
        $this->configured();
        $this->fakeFcm([
            'error' => ['message' => 'Invalid value at message.data.', 'details' => [
                ['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'INVALID_ARGUMENT'],
                ['@type' => 'type.googleapis.com/google.rpc.BadRequest', 'fieldViolations' => [['field' => 'message.data', 'description' => '...']]],
            ]],
        ], status: 400);
        $token = $this->tokenFor($this->staff());

        $result = PushSender::make()->send(collect([$token]), new PushMessage('T', 'B'));

        $this->assertSame(['sent' => 0, 'pruned' => 0, 'failed' => 1], $result);
        $this->assertDatabaseHas('push_tokens', ['id' => $token->id]);
    }

    public function test_a_401_forgets_the_cached_access_token(): void
    {
        $this->configured();
        $this->fakeFcm(['error' => ['message' => 'Request had invalid authentication credentials.']], status: 401);
        $sender = PushSender::make();
        $token = $this->tokenFor($this->staff());

        $sender->send(collect([$token]), new PushMessage('T', 'B'));
        // A second send must mint a fresh token rather than reusing the one
        // that was just rejected.
        $sender->send(collect([$token]), new PushMessage('T', 'B'));

        $this->assertCount(2, Http::recorded(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com')));
    }

    public function test_sending_without_configuration_refuses_before_calling_google(): void
    {
        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not configured');

        PushSender::make()->send(collect([$this->tokenFor($this->staff())]), new PushMessage('T', 'B'));

        Http::assertNothingSent();
    }

    public function test_one_dead_device_does_not_stop_the_others(): void
    {
        $this->configured();
        $user = $this->staff();
        $ok = $this->tokenFor($user, 'token-ok');
        $dead = $this->tokenFor($user, 'token-dead');

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'x', 'expires_in' => 3599]),
            'fcm.googleapis.com/*' => function (Request $request) {
                $body = $request->data();

                return str_contains($body['message']['token'], 'dead')
                    ? Http::response(['error' => ['message' => 'not found', 'details' => [
                        ['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED'],
                    ]]], 404)
                    : Http::response(['name' => 'x'], 200);
            },
        ]);

        $result = PushSender::make()->send(collect([$ok, $dead]), new PushMessage('T', 'B'));

        $this->assertSame(1, $result['sent']);
        $this->assertSame(1, $result['pruned']);
        $this->assertDatabaseHas('push_tokens', ['id' => $ok->id]);
        $this->assertDatabaseMissing('push_tokens', ['id' => $dead->id]);
    }
}
