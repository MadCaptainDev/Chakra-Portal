<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstagramReconnectCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_whoever_can_reconnect_sees_the_expired_and_revoked_accounts(): void
    {
        $this->withoutVite();
        $expired = Client::create(['name' => 'SVA Gold', 'phone' => '9000000001']);
        $revoked = Client::create(['name' => 'Zira', 'phone' => '9000000002']);
        $fine = Client::create(['name' => 'Fine Account Co', 'phone' => '9000000003']);
        (new SocialAccount)->forceFill(['client_id' => $expired->id, 'platform' => 'instagram', 'platform_user_id' => '1', 'access_token' => 't', 'status' => SocialAccount::STATUS_CONNECTED, 'token_expires_at' => now()->subDays(3)])->save();
        (new SocialAccount)->forceFill(['client_id' => $revoked->id, 'platform' => 'instagram', 'platform_user_id' => '2', 'access_token' => 't', 'status' => SocialAccount::STATUS_REVOKED, 'token_expires_at' => now()->addDays(30)])->save();
        (new SocialAccount)->forceFill(['client_id' => $fine->id, 'platform' => 'instagram', 'platform_user_id' => '3', 'access_token' => 't', 'status' => SocialAccount::STATUS_CONNECTED, 'token_expires_at' => now()->addDays(50)])->save();

        $manager = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $manager->syncPermissions(['clients' => ['view', 'edit', 'manage']]);
        $this->actingAs($manager->refresh())->get(route('my.dashboard'))->assertOk()
            ->assertSee('Instagram to reconnect')->assertSee('SVA Gold')->assertSee('Zira')->assertDontSee('Fine Account Co');

        $editor = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $this->actingAs($editor)->get(route('my.dashboard'))->assertOk()->assertDontSee('Instagram to reconnect');
    }
}
