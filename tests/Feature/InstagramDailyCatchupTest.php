<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstagramSyncedDaily;
use App\Models\Client;
use App\Models\SocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The daily Instagram sync rides on page visits, one account at a time:
 * the one synced longest ago first, never one already synced today, never
 * an expired connection, and each account at most once a day even if it fails.
 */
class InstagramDailyCatchupTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $name, ?string $synced, string $status = SocialAccount::STATUS_CONNECTED): SocialAccount
    {
        $a = SocialAccount::create([
            'client_id' => Client::create(['name' => $name])->id,
            'platform' => SocialAccount::PLATFORM_INSTAGRAM,
            'platform_user_id' => (string) random_int(100000, 999999),
            'username' => $name,
            'status' => $status,
        ]);
        $a->forceFill(['access_token' => 't', 'last_synced_at' => $synced])->save();

        return $a;
    }

    public function test_the_account_synced_longest_ago_goes_first_and_today_is_skipped(): void
    {
        Carbon::setTestNow('2026-11-26 09:00');
        $this->account('janet', '2026-11-26 01:00');             // done today
        $squad = $this->account('squad', '2026-10-21 21:14');    // oldest
        $this->account('mahavir', '2026-10-21 21:13:00');
        $this->account('zira', '2026-10-10 07:00', SocialAccount::STATUS_REVOKED); // expired: never
        $mahavir = SocialAccount::where('username', 'mahavir')->first();

        $this->assertSame($mahavir->id, EnsureInstagramSyncedDaily::next()->id);

        // Tried today (synced or failed): the next one's turn.
        Cache::put('instagram-sync-tried-2026-11-26-'.$mahavir->id, true, now()->endOfDay());
        $this->assertSame($squad->id, EnsureInstagramSyncedDaily::next()->id);

        $squad->forceFill(['last_synced_at' => now()])->save();
        $mahavir->forceFill(['last_synced_at' => now()])->save();
        $this->assertNull(EnsureInstagramSyncedDaily::next());
        Carbon::setTestNow();
    }

    public function test_it_runs_on_every_web_page_not_only_admin_ones(): void
    {
        $this->assertContains(EnsureInstagramSyncedDaily::class, app(\Illuminate\Contracts\Http\Kernel::class)->getMiddlewareGroups()['web']);
    }
public function test_manual_syncs_stuck_for_days_are_closed_off(): void
    {
        $a = $this->account('mahavir', null);
        $old = \App\Models\InstagramSyncJob::query()->forceCreate(['batch' => 'b1', 'social_account_id' => $a->id, 'status' => 'queued']);
        $old->forceFill(['created_at' => now()->subDays(20)])->save();

        $this->assertFalse(EnsureInstagramSyncedDaily::manualWaiting());
        $this->assertSame('failed', $old->fresh()->status);

        Cache::forget('instagram-manual-waiting');
        \App\Models\InstagramSyncJob::query()->forceCreate(['batch' => 'b2', 'social_account_id' => $a->id, 'status' => 'queued']);
        $this->assertTrue(EnsureInstagramSyncedDaily::manualWaiting());
    }
}
