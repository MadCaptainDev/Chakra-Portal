<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\NotionSetting;
use App\Services\Notion\NotionSyncRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * When the Notion cache refreshes itself.
 *
 * Notion is not read live -- a full pass is ~20 paginated calls and about
 * 11 seconds against the real workspace, and the API rate-limits at roughly
 * three requests a second. So the dashboard reads cache, and this decides
 * when that cache is too old to serve.
 */
class NotionSyncRunnerTest extends TestCase
{
    use RefreshDatabase;

    private function fakeWholeSync(): void
    {
        Http::fake([
            'api.notion.com/v1/search' => Http::response(['results' => [], 'has_more' => false]),
            'api.notion.com/*' => Http::response(['results' => [], 'has_more' => false]),
        ]);
    }

    public function test_nothing_happens_without_an_api_key(): void
    {
        Http::fake();

        NotionSyncRunner::ensureFresh();

        Http::assertNothingSent();
    }

    public function test_fresh_data_is_not_resynced(): void
    {
        NotionSetting::current()->update(['api_key' => 'secret_test']);
        ContentItem::factory()->create(['synced_at' => now()->subMinutes(2)]);

        Http::fake();

        NotionSyncRunner::ensureFresh();

        // Two minutes old is well inside the staleness window, so a page
        // view costs nothing.
        Http::assertNothingSent();
    }

    public function test_stale_data_triggers_a_sync(): void
    {
        NotionSetting::current()->update(['api_key' => 'secret_test']);
        ContentItem::factory()->create(['synced_at' => now()->subHour()]);

        $this->fakeWholeSync();

        NotionSyncRunner::ensureFresh();

        // A sync actually reached out. (Exact call count is deliberately not
        // asserted: with an empty search result the resolver falls back to
        // the configured ids unverified and queries each one, which is a
        // detail of that fallback rather than of staleness.)
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.notion.com'));
    }

    public function test_a_held_lock_stops_a_second_request_syncing_at_the_same_time(): void
    {
        NotionSetting::current()->update(['api_key' => 'secret_test']);
        ContentItem::factory()->create(['synced_at' => now()->subHour()]);

        // As if another request is already mid-sync.
        Cache::put('notion-auto-sync', true, now()->addMinutes(5));

        Http::fake();

        NotionSyncRunner::ensureFresh();

        // The second viewer renders cached data rather than queueing behind
        // an 11-second sync that is already running.
        Http::assertNothingSent();
    }

    public function test_the_lock_is_released_after_a_sync(): void
    {
        NotionSetting::current()->update(['api_key' => 'secret_test']);
        ContentItem::factory()->create(['synced_at' => now()->subHour()]);

        $this->fakeWholeSync();

        NotionSyncRunner::ensureFresh();

        $this->assertFalse(Cache::has('notion-auto-sync'));
    }

    public function test_never_synced_counts_as_stale(): void
    {
        $this->assertTrue(NotionSyncRunner::isStale());
        $this->assertNull(NotionSyncRunner::lastSyncedAt());
    }

    public function test_run_reports_per_source_counts(): void
    {
        NotionSetting::current()->update(['api_key' => 'secret_test']);
        $this->fakeWholeSync();

        $status = NotionSyncRunner::run();

        $this->assertStringContainsString('Refreshed from Notion', $status);
        $this->assertStringContainsString('reel:', $status);
    }
}
