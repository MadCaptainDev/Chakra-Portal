<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContentAccount;
use App\Models\ContentAccountVenture;
use App\Models\ContentItem;
use App\Models\NotionSetting;
use App\Models\User;
use App\Support\ContentDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Published-vs-target per content type, per account, per month.
 *
 * The attribution rules matter more than the layout: a client shown work
 * they did not get, or not shown work they did, is the failure this screen
 * exists to prevent.
 */
class ContentDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // No API key is saved, so NotionSyncRunner::ensureFresh() must be a
        // no-op. This turns any accidental network call into a failure.
        Http::preventStrayRequests();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    /**
     * @param  array<string, int>  $targets  keyed by source
     */
    private function account(string $clientName, string $accountName, string $venture, array $targets = ['reel' => 10]): ContentAccount
    {
        $client = Client::firstOrCreate(['name' => $clientName]);

        $account = ContentAccount::create([
            'client_id' => $client->id,
            'name' => $accountName,
        ] + collect($targets)->mapWithKeys(fn (int $v, string $k) => ['target_'.$k => $v])->all());

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

    private function august(): Carbon
    {
        return Carbon::parse('2026-08-01');
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
        $this->account('SVA Silks and Readymades', 'SVA Silks', 'SVA Silks');

        $this->published('SVA Silks', ContentItem::SOURCE_REEL, '2026-07-10');
        $this->published('SVA Silks', ContentItem::SOURCE_REEL, '2026-08-10');
        $this->published('SVA Silks', ContentItem::SOURCE_REEL, '2026-08-28');

        $data = ContentDashboard::forMonth($this->august());

        $this->assertSame(2, $data['grandTotal']);
        // Last month is carried for the comparison column.
        $this->assertSame(1, $data['grandPrevious']);
    }

    /**
     * The reason targets are per type rather than one number: a month can
     * hit its total while the mix is entirely wrong.
     */
    public function test_each_content_type_is_counted_and_targeted_independently(): void
    {
        $this->account('Janet', 'Janet', 'Janet', ['reel' => 4, 'post' => 2, 'youtube' => 1]);

        foreach (range(1, 5) as $i) {
            $this->published('Janet', ContentItem::SOURCE_REEL, '2026-08-0'.$i);
        }
        $this->published('Janet', ContentItem::SOURCE_POST, '2026-08-06');
        // No YouTube at all this month.

        $row = ContentDashboard::forMonth($this->august())['clients']->first()['rows']->first();

        $this->assertSame(5, $row['types'][ContentItem::SOURCE_REEL]['actual']);
        $this->assertSame(4, $row['types'][ContentItem::SOURCE_REEL]['target']);
        $this->assertSame(1, $row['types'][ContentItem::SOURCE_REEL]['variance']);

        $this->assertSame(1, $row['types'][ContentItem::SOURCE_POST]['actual']);
        $this->assertSame(-1, $row['types'][ContentItem::SOURCE_POST]['variance']);

        // Missed entirely, and said so rather than omitted.
        $this->assertSame(0, $row['types'][ContentItem::SOURCE_YOUTUBE]['actual']);
        $this->assertSame(-1, $row['types'][ContentItem::SOURCE_YOUTUBE]['variance']);

        // Total is the targeted types only: 5 + 1 + 0 against 4 + 2 + 1.
        $this->assertSame(6, $row['total']);
        $this->assertSame(7, $row['target']);
    }

    public function test_stories_are_counted_but_never_targeted(): void
    {
        $this->account('Janet', 'Janet', 'Janet', ['reel' => 1]);

        $this->published('Janet', ContentItem::SOURCE_REEL, '2026-08-01');
        $this->published('Janet', ContentItem::SOURCE_STORY, '2026-08-02');
        $this->published('Janet', ContentItem::SOURCE_STORY, '2026-08-03');

        $row = ContentDashboard::forMonth($this->august())['clients']->first()['rows']->first();

        $this->assertSame(2, $row['stories']);
        // Stories stay out of the total, so a good story month cannot mask
        // a missed reel target.
        $this->assertSame(1, $row['total']);
    }

    public function test_an_account_with_no_target_is_hidden_but_counted_as_outstanding(): void
    {
        $this->account('Zira Bridal Studio', 'Zira', 'Zira', targets: []);
        $this->published('Zira', ContentItem::SOURCE_REEL, '2026-08-05');

        $data = ContentDashboard::forMonth($this->august());

        $this->assertTrue($data['clients']->isEmpty());
        $this->assertSame(0, $data['grandTotal']);
        // Not silently gone -- the screen offers a way to go and set one.
        $this->assertSame(1, $data['untargetedAccounts']);
    }

    public function test_two_accounts_under_one_client_are_counted_and_targeted_separately(): void
    {
        // The case that made ContentAccount necessary: SVA Silks runs two
        // publishing identities with their own targets.
        $this->account('SVA Silks and Readymades', 'SVA Silks', 'SVA Silks', ['reel' => 10]);
        $this->account('SVA Silks and Readymades', 'SVA Womenswear', 'Sva womenswear', ['reel' => 4]);

        foreach (range(1, 6) as $i) {
            $this->published('SVA Silks', ContentItem::SOURCE_REEL, '2026-08-0'.$i);
        }
        $this->published('Sva womenswear', ContentItem::SOURCE_REEL, '2026-08-11');

        $data = ContentDashboard::forMonth($this->august());
        $rows = $data['clients']->first()['rows']->keyBy(fn ($r) => $r['account']->name);

        $this->assertSame(6, $rows['SVA Silks']['total']);
        $this->assertSame(-4, $rows['SVA Silks']['variance']);
        $this->assertSame(1, $rows['SVA Womenswear']['total']);

        // Both under one client, whose subtotal is the sum of the two.
        $this->assertCount(1, $data['clients']);
        $this->assertSame(7, $data['clients']->first()['total']);
        $this->assertSame(14, $data['clients']->first()['target']);
    }

    public function test_a_venture_belonging_to_no_account_is_reported_not_silently_dropped(): void
    {
        $this->account('Riya Makeover Artisty', 'Riya', 'Riya');

        $this->published('Riya', ContentItem::SOURCE_REEL, '2026-08-05');
        $this->published('PR', ContentItem::SOURCE_REEL, '2026-08-06');
        $this->published('PR', ContentItem::SOURCE_REEL, '2026-08-07');

        $data = ContentDashboard::forMonth($this->august());

        $this->assertSame(1, $data['grandTotal']);
        $this->assertSame(2, $data['unmappedThisMonth']);
        $this->assertSame('PR', $data['unmapped']->first()->venture);
    }

    public function test_unpublished_and_undated_items_are_not_counted(): void
    {
        $this->account('Thor Gym', 'Thor Gym', 'THOR');

        $this->published('THOR', ContentItem::SOURCE_REEL, '2026-08-05');

        ContentItem::factory()->create([
            'source' => ContentItem::SOURCE_REEL, 'venture' => 'THOR',
            'status' => 'Idea', 'published_date' => '2026-08-06',
        ]);
        ContentItem::factory()->create([
            'source' => ContentItem::SOURCE_REEL, 'venture' => 'THOR',
            'status' => 'Published', 'published_date' => null,
        ]);

        $this->assertSame(1, ContentDashboard::forMonth($this->august())['grandTotal']);
    }

    public function test_the_page_renders_with_real_rows(): void
    {
        $this->account('Janet', 'Janet Hospitals', 'Janet', ['reel' => 5]);
        $this->published('Janet', ContentItem::SOURCE_REEL, '2026-08-05');

        $this->actingAs($this->admin())
            ->get(route('content-dashboard.index').'?month=2026-08')
            ->assertOk()
            ->assertSee('Janet Hospitals')
            ->assertSee('August 2026')
            ->assertSee('Insta Reel');
    }

    /**
     * The redesign this pins: one card per account, split by platform
     * rather than one flat "published" number. A card carrying only a Reel
     * target must still show the real Instagram brand mark next to it, not
     * a generic icon that could be mistaken for anything.
     */
    public function test_each_targeted_type_gets_its_own_row_with_the_right_platform_mark(): void
    {
        $this->account('Janet', 'Janet Hospitals', 'Janet', ['reel' => 5, 'post' => 3, 'youtube' => 2]);
        $this->published('Janet', ContentItem::SOURCE_REEL, '2026-08-05');
        $this->published('Janet', ContentItem::SOURCE_POST, '2026-08-06');
        $this->published('Janet', ContentItem::SOURCE_YOUTUBE, '2026-08-07');

        $response = $this->actingAs($this->admin())
            ->get(route('content-dashboard.index').'?month=2026-08');

        $response->assertOk()
            ->assertSee('Insta Reel')
            ->assertSee('Insta Post')
            ->assertSee('YouTube Shorts')
            // Each type's own actual/target pair, not just one combined total.
            ->assertSeeInOrder(['1', '/5'], false)
            ->assertSeeInOrder(['1', '/3'], false)
            ->assertSeeInOrder(['1', '/2'], false);

        // Instagram's mark (the gradient defined in brand-icon.blade.php)
        // appears for Reel and Post; YouTube's for Shorts.
        $response->assertSee('ig-grad-', false);
        $response->assertSee('#FF0000', false);
    }

    public function test_stories_are_shown_but_carry_no_target_row(): void
    {
        $this->account('Janet', 'Janet Hospitals', 'Janet', ['reel' => 5]);
        $this->published('Janet', ContentItem::SOURCE_STORY, '2026-08-05');

        $this->actingAs($this->admin())
            ->get(route('content-dashboard.index').'?month=2026-08')
            ->assertOk()
            ->assertSee('Stories');
    }

    public function test_the_drill_down_lists_the_months_pieces(): void
    {
        $account = $this->account('Janet', 'Janet Hospitals', 'Janet', ['reel' => 5]);
        $this->published('Janet', ContentItem::SOURCE_REEL, '2026-08-05', 'Independence Day Reel');
        $this->published('Janet', ContentItem::SOURCE_REEL, '2026-07-05', 'Last month reel');

        $this->actingAs($this->admin())
            ->get(route('content-dashboard.show', [$account, 'month' => '2026-08']))
            ->assertOk()
            ->assertSee('Independence Day Reel')
            ->assertDontSee('Last month reel');
    }

    /**
     * The type filter row is the gap this pins: the drill-down already had
     * a status filter (Published/In Progress/...), but nothing to narrow
     * by content type, even though the per-type breakdown cards right
     * above it already show the split.
     */
    public function test_a_type_filter_chip_appears_per_content_type_present_that_month(): void
    {
        $account = $this->account('Janet', 'Janet Hospitals', 'Janet', ['reel' => 5, 'post' => 3]);
        $this->published('Janet', ContentItem::SOURCE_REEL, '2026-08-05', 'A reel');
        $this->published('Janet', ContentItem::SOURCE_POST, '2026-08-06', 'A post');

        $response = $this->actingAs($this->admin())
            ->get(route('content-dashboard.show', [$account, 'month' => '2026-08']));

        $response->assertOk()
            ->assertSee('Insta Reel')
            ->assertSee('Insta Post')
            // Instagram's brand mark, confirming these chips reuse
            // x-brand-icon rather than a generic glyph.
            ->assertSee('ig-grad-', false);
    }

    /**
     * A single-type account gets no type filter row at all -- a toggle
     * with nothing to narrow down is noise, not a filter.
     */
    public function test_no_type_filter_row_when_only_one_content_type_is_present(): void
    {
        $account = $this->account('Janet', 'Janet Hospitals', 'Janet', ['reel' => 5]);
        $this->published('Janet', ContentItem::SOURCE_REEL, '2026-08-05', 'Only a reel');

        $response = $this->actingAs($this->admin())
            ->get(route('content-dashboard.show', [$account, 'month' => '2026-08']));

        $response->assertOk()->assertDontSee("types['reel']", false);
    }

    public function test_an_invalid_month_falls_back_instead_of_erroring(): void
    {
        $this->account('Janet', 'Janet', 'Janet');
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
        // preventStrayRequests() in setUp is the assertion here.
        $this->actingAs($this->admin())
            ->get(route('content-dashboard.index'))
            ->assertOk();
    }
}
