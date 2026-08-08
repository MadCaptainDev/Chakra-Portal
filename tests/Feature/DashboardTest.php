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

    public function test_mobile_tab_strip_and_panel_tagging_are_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('dashboardTabs()', false);
        $response->assertSee('aria-label="Dashboard sections"', false);

        // The CSS tab filter keys off these two attributes, so the markup
        // contract matters more than usual: a missing data-panel would leave
        // that widget permanently hidden on mobile.
        $response->assertSee('data-tab="overview"', false);

        foreach (['cashflow', 'outstanding', 'overview', 'splits'] as $panel) {
            $response->assertSee('data-panel="'.$panel.'"', false);
        }
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
        $response->assertSee("This Month's Outflow", false);
        $response->assertViewHas('outflowDue', 24688.0);   // 7500 + 15000 + 2188
        $response->assertViewHas('outflowPaid', 0.0);
        $response->assertViewHas('outflowPending', 24688.0);
        $response->assertViewHas('emiThisMonth', 2188.0);

        // The invoice figures must survive the addition.
        $response->assertSee('10,000.00');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }
}
