<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContentAccount;
use App\Models\ContentAccountVenture;
use App\Models\ContentItem;
use App\Models\NotionSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Videos published per client account per month, against target.
 *
 * The attribution rules matter more than the layout here: a client being
 * shown work they did not get, or not shown work they did, is the failure
 * this screen exists to prevent.
 */
class ContentDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // No API key: NotionSyncRunner::ensureFresh() must then be a no-op,
        // so these tests never reach for the network. The one test that
        // cares about refreshing sets a key itself.
        Http::preventStrayRequests();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function accountWithVenture(string $clientName, string $accountName, string $venture, ?int $target = null): ContentAccount
    {
        $client = Client::firstOrCreate(['name' => $clientName]);

        $account = ContentAccount::create([
            'client_id' => $client->id,
            'name' => $accountName,
            'monthly_target' => $target,
        ]);

        ContentAccountVenture::create(['content_account_id' => $account->id, 'venture' => $venture]);

        return $account;
    }

    private function published(string $venture, string $source, string $date, string $title = 'Item'): ContentItem
    {
        return ContentItem::factory()->create([
            'source' => $source,
            'venture' => $venture,
            'status' => 'Published',
            'published_date' => $date,
            'title' => $title,
        ]);
    }

    public function test_a_guest_and_an_employee_reach_none_of_it(): void
    {
        $this->get(route('content-dashboard.index'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))
            ->get(route('content-dashboard.index'))
            ->assertForbidden();
    }

    public function test_it_counts_only_the_selected_month(): void
    {
        $this->accountWithVenture('SVA Silks and Readymades', 'SVA Silks', 'SVA Silks');

        $this->published('SVA Silks', ContentItem::SOURCE_REEL, '2026-07-10', 'July reel');
        $this->published('SVA Silks', ContentItem::SOURCE_REEL, '2026-08-10', 'August reel');
        $this->published('SVA Silks', ContentItem::SOURCE_REEL, '2026-08-28', 'Another August reel');

        $data = \App\Support\ContentDashboard::forMonth(\Illuminate\Support\Carbon::parse('2026-08-01'));

        $this->assertSame(2, $data['grandTotal']);
        // Previous month is carried for the vs-last-month comparison.
        $this->assertSame(1, $data['grandPrevious']);
    }

    public function test_two_accounts_under_one_client_are_counted_and_targeted_separately(): void
    {
        // The exact case that made ContentAccount necessary: SVA Silks runs
        // two publishing identities with their own targets.
        $client = Client::create(['name' => 'SVA Silks and Readymades']);

        $silks = ContentAccount::create(['client_id' => $client->id, 'name' => 'SVA Silks', 'monthly_target' => 10]);
        ContentAccountVenture::create(['content_account_id' => $silks->id, 'venture' => 'SVA Silks']);

        $women = ContentAccount::create(['client_id' => $client->id, 'name' => 'SVA Womenswear', 'monthly_target' => 4]);
        ContentAccountVenture::create(['content_account_id' => $women->id, 'venture' => 'Sva womenswear']);

        foreach (range(1, 6) as $i) {
            $this->published('SVA Silks', ContentItem::SOURCE_REEL, '2026-08-0'.min($i, 9));
        }
        $this->published('Sva womenswear', ContentItem::SOURCE_REEL, '2026-08-11');

        $data = \App\Support\ContentDashboard::forMonth(\Illuminate\Support\Carbon::parse('2026-08-01'));

        $rows = $data['clients']->first()['rows']->keyBy(fn ($r) => $r['account']->name);

        $this->assertSame(6, $rows['SVA Silks']['total']);
        $this->assertSame(10, $rows['SVA Silks']['target']);
        $this->assertSame(-4, $rows['SVA Silks']['variance']);

        $this->assertSame(1, $rows['SVA Womenswear']['total']);
        $this->assertSame(4, $rows['SVA Womenswear']['target']);

        // Both belong to the one client, whose subtotal is the sum of both.
        $this->assertCount(1, $data['clients']);
        $this->assertSame(7, $data['clients']->first()['total']);
        $this->assertSame(14, $data['clients']->first()['target']);
    }

    public function test_a_venture_belonging_to_no_account_is_reported_not_silently_dropped(): void
    {
        $this->accountWithVenture('Riya Makeover Artisty', 'Riya', 'Riya');

        $this->published('Riya', ContentItem::SOURCE_REEL, '2026-08-05');
        $this->published('PR', ContentItem::SOURCE_REEL, '2026-08-06');
        $this->published('PR', ContentItem::SOURCE_REEL, '2026-08-07');

        $data = \App\Support\ContentDashboard::forMonth(\Illuminate\Support\Carbon::parse('2026-08-01'));

        // Not counted in any account's total...
        $this->assertSame(1, $data['grandTotal']);
        // ...but visibly reported rather than quietly missing.
        $this->assertSame(2, $data['unmappedThisMonth']);
        $this->assertSame('PR', $data['unmapped']->first()->venture);
    }

    public function test_unpublished_and_undated_items_are_not_counted(): void
    {
        $this->accountWithVenture('Thor Gym', 'Thor Gym', 'THOR');

        $this->published('THOR', ContentItem::SOURCE_REEL, '2026-08-05');

        ContentItem::factory()->create([
            'source' => ContentItem::SOURCE_REEL, 'venture' => 'THOR',
            'status' => 'Idea', 'published_date' => '2026-08-06',
        ]);
        ContentItem::factory()->create([
            'source' => ContentItem::SOURCE_REEL, 'venture' => 'THOR',
            'status' => 'Published', 'published_date' => null,
        ]);

        $data = \App\Support\ContentDashboard::forMonth(\Illuminate\Support\Carbon::parse('2026-08-01'));

        $this->assertSame(1, $data['grandTotal']);
    }

    public function test_counts_are_broken_down_by_source(): void
    {
        $this->accountWithVenture('Janet', 'Janet', 'Janet');

        $this->published('Janet', ContentItem::SOURCE_REEL, '2026-08-05');
        $this->published('Janet', ContentItem::SOURCE_REEL, '2026-08-06');
        $this->published('Janet', ContentItem::SOURCE_YOUTUBE, '2026-08-07');
        $this->published('Janet', ContentItem::SOURCE_POST, '2026-08-08');

        $data = \App\Support\ContentDashboard::forMonth(\Illuminate\Support\Carbon::parse('2026-08-01'));
        $row = $data['clients']->first()['rows']->first();

        $this->assertSame(2, $row['counts'][ContentItem::SOURCE_REEL]);
        $this->assertSame(1, $row['counts'][ContentItem::SOURCE_YOUTUBE]);
        $this->assertSame(1, $row['counts'][ContentItem::SOURCE_POST]);
        $this->assertSame(4, $row['total']);
    }

    public function test_an_account_with_no_target_reports_null_rather_than_zero_percent(): void
    {
        $this->accountWithVenture('Zira Bridal Studio', 'Zira', 'Zira', target: null);
        $this->published('Zira', ContentItem::SOURCE_REEL, '2026-08-05');

        $data = \App\Support\ContentDashboard::forMonth(\Illuminate\Support\Carbon::parse('2026-08-01'));
        $row = $data['clients']->first()['rows']->first();

        $this->assertNull($row['target']);
        $this->assertNull($row['variance']);
        $this->assertNull($row['pct']);
    }

    public function test_the_page_renders_with_real_rows(): void
    {
        $this->accountWithVenture('Janet', 'Janet Hospitals', 'Janet', target: 5);
        $this->published('Janet', ContentItem::SOURCE_REEL, '2026-08-05');

        $this->actingAs($this->admin())
            ->get(route('content-dashboard.index').'?month=2026-08')
            ->assertOk()
            ->assertSee('Janet Hospitals')
            ->assertSee('August 2026');
    }

    public function test_an_invalid_month_falls_back_instead_of_erroring(): void
    {
        $this->accountWithVenture('Janet', 'Janet', 'Janet');
        $this->published('Janet', ContentItem::SOURCE_REEL, '2026-08-05');

        $this->actingAs($this->admin())
            ->get(route('content-dashboard.index').'?month=not-a-month')
            ->assertOk();
    }

    public function test_refresh_without_a_key_reports_it_rather_than_failing(): void
    {
        $this->assertNull(NotionSetting::current()->api_key);

        $this->actingAs($this->admin())
            ->post(route('content-dashboard.refresh'))
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $s) => str_contains($s, 'No Notion API key'));
    }

    public function test_a_stale_view_does_not_call_notion_when_no_key_is_saved(): void
    {
        // preventStrayRequests() in setUp is the assertion: if ensureFresh()
        // tried to sync without a key, this request would throw.
        $this->actingAs($this->admin())
            ->get(route('content-dashboard.index'))
            ->assertOk();
    }
}
