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

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }
}
