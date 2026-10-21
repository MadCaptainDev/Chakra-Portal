<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\InstagramSyncJob;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Instagram\InstagramSyncQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "Sync all Instagram": the button lines up every account, the worker goes
 * through them one at a time, renews a connection near expiry, and marks a
 * dead one for reconnecting instead of failing it quietly.
 */
class InstagramSyncQueueTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $client, string $uid, $expires): SocialAccount
    {
        $c = Client::create(['name' => $client]);
        $a = SocialAccount::create(['client_id' => $c->id, 'platform' => SocialAccount::PLATFORM_INSTAGRAM, 'platform_user_id' => $uid,
            'username' => strtolower(str_replace(' ', '', $client)), 'status' => SocialAccount::STATUS_CONNECTED]);
        $a->forceFill(['access_token' => 'old-'.$uid, 'token_expires_at' => $expires, 'connected_at' => now()->subDays(50), 'last_synced_at' => now()->subDays(5)])->save();

        return $a;
    }

    public function test_the_queue_renews_syncs_and_flags_expired_accounts(): void
    {
        Http::fake([
            '*refresh_access_token*' => Http::response(['access_token' => 'fresh-token', 'expires_in' => 5_184_000]),
            '*' => Http::response(['data' => []]),
        ]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $soon = $this->account('Suryas Groups of Companies', '17841400000000101', now()->addDays(5));
        $dead = $this->account('Digital Harvest', '17841400000000102', now()->subDays(4));

        $this->withoutVite();
        app(InstagramSyncQueue::class)->queueAll($admin);
        $this->assertSame(2, InstagramSyncJob::count());

        // Pressing it again while a run is going does not queue a second run.
        app(InstagramSyncQueue::class)->queueAll($admin);
        $this->assertSame(2, InstagramSyncJob::count());

        app(InstagramSyncQueue::class)->work(30);

        $this->assertSame(InstagramSyncJob::RECONNECT, InstagramSyncJob::where('social_account_id', $dead->id)->value('status'));
        $this->assertNotSame(InstagramSyncJob::QUEUED, InstagramSyncJob::where('social_account_id', $soon->id)->value('status'));
        $this->assertSame('fresh-token', $soon->fresh()->access_token);
        $this->assertTrue($soon->fresh()->token_expires_at->isAfter(now()->addDays(50)));

        $this->actingAs($admin)->getJson(route('instagram-sync.status'))->assertOk()->assertJsonPath('running', false)->assertJsonPath('counts.reconnect', 1);
        $this->actingAs($admin)->get(route('instagram-sync.index'))->assertOk()->assertSee('Sync all Instagram');
        // The button queues a new run and starts on it straight away.
        $this->post(route('instagram-sync.store'))->assertRedirect(route('instagram-sync.index'));
        $this->assertSame(4, InstagramSyncJob::count());

        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))->post(route('instagram-sync.store'))->assertForbidden();
    }
}
