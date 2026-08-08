<?php

namespace Tests\Feature;

use App\Models\Invoice;
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
}
