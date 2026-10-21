<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Money Overview: the month in and out, overdue, to come in and to pay -- admins only. */
class MoneyOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_sees_overdue_and_coming_money_and_others_cannot(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = Client::create(['name' => 'Suryas Groups of Companies']);
        Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'total' => 42500, 'due_date' => today()->subDays(14), 'invoice_number' => 'CP-0028']);
        Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'total' => 9000, 'due_date' => today()->addDays(5), 'invoice_number' => 'CP-0040']);

        $this->withoutVite();
        $this->actingAs($admin)->get(route('money.index'))->assertOk()
            ->assertSeeInOrder(['Came in', 'Went out', 'Net', 'Overdue', '₹42,500'])
            ->assertSeeInOrder(['Overdue invoices', 'CP-0028', '14 days'])
            ->assertSeeInOrder(['Still to come in', 'CP-0040']);
        $this->get(route('dashboard'))->assertSee(route('money.index'), false);

        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))->get(route('money.index'))->assertForbidden();
    }
}
