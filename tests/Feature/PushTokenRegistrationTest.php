<?php

namespace Tests\Feature;

use App\Models\PushToken;
use App\Models\User;
use App\Support\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A browser registering, refreshing, and dropping its push token.
 *
 * The interesting rule here is not "can you POST a token" -- it's what
 * happens when the SAME token shows up twice under two different users.
 * See PushToken::register()'s docblock: one browser profile is one FCM
 * token, so the second registration must move the row, not duplicate it.
 */
class PushTokenRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
    }

    private function client(): User
    {
        return User::factory()->create(['role' => User::ROLE_CLIENT]);
    }

    public function test_a_staff_member_can_register_a_token(): void
    {
        $user = $this->staff();

        $this->actingAs($user)
            ->postJson('/profile/push-tokens', ['token' => 'fcm-token-one'])
            ->assertOk()
            ->assertJson(['status' => 'registered']);

        $this->assertDatabaseCount('push_tokens', 1);
        $this->assertDatabaseHas('push_tokens', [
            'user_id' => $user->id,
            'token_hash' => hash('sha256', 'fcm-token-one'),
        ]);
    }

    public function test_a_client_is_refused(): void
    {
        $this->actingAs($this->client())
            ->postJson('/profile/push-tokens', ['token' => 'fcm-token-one'])
            ->assertForbidden();

        $this->assertDatabaseCount('push_tokens', 0);
    }

    public function test_the_device_label_and_kind_come_from_the_user_agent(): void
    {
        $user = $this->staff();
        $userAgent = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/120.0 Mobile/15E148 Safari/604.1';

        $this->actingAs($user)
            ->withHeaders(['User-Agent' => $userAgent])
            ->postJson('/profile/push-tokens', ['token' => 'fcm-token-iphone'])
            ->assertOk();

        $expected = Device::describe($userAgent);

        $this->assertDatabaseHas('push_tokens', [
            'user_id' => $user->id,
            'device_label' => $expected['label'],
            'device_kind' => $expected['kind'],
        ]);
    }

    public function test_the_same_token_posted_by_a_second_user_moves_the_row_instead_of_duplicating_it(): void
    {
        $first = $this->staff();
        $second = $this->staff();

        $this->actingAs($first)
            ->postJson('/profile/push-tokens', ['token' => 'shared-studio-imac'])
            ->assertOk();

        $this->actingAs($second)
            ->postJson('/profile/push-tokens', ['token' => 'shared-studio-imac'])
            ->assertOk();

        $this->assertDatabaseCount('push_tokens', 1);
        $this->assertDatabaseHas('push_tokens', [
            'user_id' => $second->id,
            'token_hash' => hash('sha256', 'shared-studio-imac'),
        ]);
    }

    public function test_re_registering_clears_a_previous_failure(): void
    {
        $user = $this->staff();

        $token = PushToken::register($user, 'stale-token', null);
        $token->markFailed('410 UNREGISTERED');

        $this->actingAs($user)
            ->postJson('/profile/push-tokens', ['token' => 'stale-token'])
            ->assertOk();

        $token->refresh();
        $this->assertNull($token->failure_reason);
        $this->assertNull($token->last_failed_at);
    }

    public function test_revoking_deletes_only_the_matching_token_for_this_user(): void
    {
        $user = $this->staff();
        PushToken::register($user, 'token-to-revoke', null);

        $this->actingAs($user)
            ->postJson('/profile/push-tokens/revoke', ['token' => 'token-to-revoke'])
            ->assertOk()
            ->assertJson(['status' => 'revoked']);

        $this->assertDatabaseCount('push_tokens', 0);
    }

    public function test_revoking_a_token_that_does_not_exist_does_not_error(): void
    {
        $this->actingAs($this->staff())
            ->postJson('/profile/push-tokens/revoke', ['token' => 'never-registered'])
            ->assertOk()
            ->assertJson(['status' => 'revoked']);
    }

    public function test_revoking_someone_elses_token_leaves_it_in_place(): void
    {
        $owner = $this->staff();
        $intruder = $this->staff();
        PushToken::register($owner, 'owners-token', null);

        $this->actingAs($intruder)
            ->postJson('/profile/push-tokens/revoke', ['token' => 'owners-token'])
            ->assertOk();

        $this->assertDatabaseCount('push_tokens', 1);
    }

    public function test_a_user_can_delete_their_own_registered_device(): void
    {
        $user = $this->staff();
        $token = PushToken::register($user, 'my-own-device', null);

        $this->actingAs($user)
            ->delete(route('push-tokens.destroy', $token))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseCount('push_tokens', 0);
    }

    /**
     * 404, not 403: someone else's registered device is not this user's to
     * even know exists. Matches McpTokenController::destroy()'s stance.
     */
    public function test_a_user_cannot_delete_someone_elses_device_and_gets_a_404_not_a_403(): void
    {
        $owner = $this->staff();
        $intruder = $this->staff();
        $token = PushToken::register($owner, 'not-yours', null);

        $this->actingAs($intruder)
            ->delete(route('push-tokens.destroy', $token))
            ->assertNotFound();

        $this->assertDatabaseCount('push_tokens', 1);
    }
}
