<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\SocialAccount;
use App\Models\SocialInsight;
use App\Models\SocialMediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_loads_with_correct_stat_totals(): void
    {
        $user = User::factory()->create();
        Invoice::factory()->create(['subtotal' => 10000, 'total' => 10000]);
        Invoice::factory()->create(['subtotal' => 5000, 'total' => 5000]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('15,000.00');
    }

    public function test_pending_approval_banner_hidden_when_nothing_pending(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertDontSee('waiting for your approval');
    }

    public function test_pending_approval_banner_shown_when_invoices_are_pending(): void
    {
        $user = User::factory()->create();
        Invoice::factory()->pendingApproval()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertSee('waiting for your approval');
    }

    public function test_dashboard_pulls_in_no_external_scripts_or_styles(): void
    {
        // The dashboard used to fetch GridStack and Chart.js from jsdelivr for a
        // draggable widget grid. Both are gone, and the brief forbids a CDN, so
        // this asserts the absence rather than trusting nobody re-adds one.
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertDontSee('cdn.jsdelivr.net', false);
        $response->assertDontSee('gridstack', false);
        $response->assertDontSee('<canvas', false);
    }

    public function test_dashboard_renders_the_headline_blocks(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Needs attention');
        $response->assertSee('Collected vs paid out');
        $response->assertSee('Unpaid invoices');
    }

    public function test_bottleneck_rows_link_to_the_screen_that_clears_them(): void
    {
        $user = User::factory()->create();

        // An unpaid salary is a bottleneck, and its row should deep-link to the
        // salaries month that settles it.
        \App\Models\Expense::create([
            'name' => 'Kanishka', 'type' => \App\Models\Expense::TYPE_SALARY,
            'amount' => 15000, 'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Where cash is stuck');
        $response->assertSee(route('salaries.index', ['month' => now()->format('Y-m')]), false);
    }

    public function test_dashboard_shows_this_months_outflow_alongside_invoices(): void
    {
        $user = User::factory()->create();
        Invoice::factory()->create(['subtotal' => 10000, 'total' => 10000]);

        // Due every month, so they land in the current month whenever this runs.
        \App\Models\Expense::create(['name' => 'Rent', 'type' => \App\Models\Expense::TYPE_BILL, 'amount' => 7500, 'is_active' => true]);
        \App\Models\Expense::create(['name' => 'Kanishka', 'type' => \App\Models\Expense::TYPE_SALARY, 'amount' => 15000, 'is_active' => true]);
        \App\Models\Expense::create([
            'name' => 'Gimbal', 'type' => \App\Models\Expense::TYPE_EMI, 'amount' => 2188,
            'start_month' => now()->startOfMonth()->toDateString(), 'installments' => 12,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        // Escaped, not raw: the heading now renders through x-section-heading,
        // so the apostrophe reaches the page as &#039;. Same text on screen.
        $response->assertSee("This Month's Outflow");
        $response->assertViewHas('outflowDue', 24688.0);   // 7500 + 15000 + 2188
        $response->assertViewHas('outflowPaid', 0.0);
        $response->assertViewHas('outflowPending', 24688.0);
        $response->assertViewHas('emiThisMonth', 2188.0);

        // The invoice figures must survive the addition.
        $response->assertSee('10,000.00');
    }

    public function test_expense_mix_bars_are_drawn_from_real_amounts(): void
    {
        // The bar widths are computed in Blade now that Chart.js is gone, so the
        // arithmetic is worth pinning: the largest row is the 100% baseline and
        // a smaller row scales against it.
        $user = User::factory()->create();

        \App\Models\Expense::create(['name' => 'Kanishka', 'type' => \App\Models\Expense::TYPE_SALARY, 'amount' => 20000, 'is_active' => true]);
        \App\Models\Expense::create(['name' => 'Rent', 'type' => \App\Models\Expense::TYPE_BILL, 'amount' => 5000, 'is_active' => true]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Expense mix');

        // Salary is the largest, so it sets the baseline; bills are a quarter of it.
        $response->assertSee('width: 100%; background-color: #16a34a', false);
        $response->assertSee('width: 25%; background-color: #0284c7', false);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_the_dashboard_surfaces_an_unadded_high_performing_post(): void
    {
        $client = Client::create(['name' => 'Chakra Production']);
        $account = SocialAccount::create([
            'client_id' => $client->id,
            'platform' => SocialAccount::PLATFORM_INSTAGRAM,
            'platform_user_id' => '17841470000000001',
            'username' => 'client_'.$client->id,
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
        $account->forceFill(['access_token' => 'IGQV-token', 'connected_at' => now()])->save();

        $media = SocialMediaItem::create([
            'social_account_id' => $account->id,
            'platform_media_id' => '1',
            'media_type' => SocialMediaItem::TYPE_VIDEO,
            'media_product_type' => SocialMediaItem::PRODUCT_REELS,
            'caption' => 'A caption worth reading',
            'permalink' => 'https://www.instagram.com/p/1/',
            'posted_at' => now()->subDays(3),
            'cached_at' => now(),
        ]);
        SocialInsight::record([
            'social_account_id' => $account->id,
            'social_media_item_id' => $media->id,
            'metric' => 'views',
            'metric_type' => SocialInsight::TYPE_TOTAL_VALUE,
            'value' => 50000,
            'period' => 'lifetime',
            'period_start' => now()->toDateString(),
        ]);

        $response = $this->actingAs(User::factory()->create())->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('outperforming your portfolio');
        // Blade escapes the & in the query string to &amp; -- assertSee's
        // raw (false) mode compares literal HTML, so the expectation has to
        // match what actually lands on the page rather than route()'s own
        // unescaped output.
        $expectedHref = str_replace('&', '&amp;', route('portfolio.create', [
            'client_id' => $client->id,
            'media_id' => $media->id,
        ]));
        $response->assertSee($expectedHref, false);
    }

    public function test_an_account_behind_target_shows_a_suggested_shoot_by_date(): void
    {
        $client = Client::create(['name' => 'SVA Silks and Readymades']);
        $account = \App\Models\ContentAccount::create([
            'client_id' => $client->id, 'name' => 'Instagram', 'target_reel' => 10,
        ]);
        \App\Models\ContentAccountVenture::create([
            'content_account_id' => $account->id, 'venture' => $client->name,
        ]);

        $response = $this->actingAs(User::factory()->create())->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Shoot by');
    }

    public function test_no_suggestion_is_offered_once_the_edit_buffer_has_already_passed(): void
    {
        // The buffer is 5 days before month end -- travel to inside that
        // window so the only honest answer left is "as soon as possible".
        $this->travelTo(now()->endOfMonth()->subDays(2));

        $client = Client::create(['name' => 'SVA Silks and Readymades']);
        $account = \App\Models\ContentAccount::create([
            'client_id' => $client->id, 'name' => 'Instagram', 'target_reel' => 10,
        ]);
        \App\Models\ContentAccountVenture::create([
            'content_account_id' => $account->id, 'venture' => $client->name,
        ]);

        $response = $this->actingAs(User::factory()->create())->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('ASAP');
    }
}
