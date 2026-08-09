<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\TimesheetEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_contact_details_and_invoice_history(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create([
            'name' => 'Thor Gym',
            'address' => 'Manapparai, Tamil Nadu',
            'phone' => '9876543210',
        ]);
        Invoice::factory()->create(['client_id' => $client->id, 'invoice_number' => 'CP-0042']);

        $response = $this->actingAs($user)->get(route('clients.show', $client));

        $response->assertOk();
        $response->assertSee('Thor Gym');
        $response->assertSee('Manapparai, Tamil Nadu');
        $response->assertSee('9876543210');
        $response->assertSee('CP-0042');
        $response->assertSee('Timesheet hours');
    }

    public function test_shows_correct_timesheet_hours_for_the_client(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $client = Client::factory()->create([
            'name' => 'Riya Makeover Artisty',
            'notion_venture' => 'Riya',
        ]);
        Client::factory()->create([
            'name' => 'SVA Silks and Readymades',
            'notion_venture' => 'SVA Silks',
        ]);

        TimesheetEntry::create([
            'user_id' => $employee->id,
            'worked_on' => today()->toDateString(),
            'task' => 'Shoot',
            'task_type' => 'shooting',
            'venture' => 'Riya',
            'minutes' => 120,
            'status' => 'completed',
        ]);
        TimesheetEntry::create([
            'user_id' => $employee->id,
            'worked_on' => today()->toDateString(),
            'task' => 'Editing',
            'task_type' => 'editing',
            'venture' => 'Riya',
            'minutes' => 60,
            'status' => 'completed',
        ]);
        // Different client — must not inflate Riya totals.
        TimesheetEntry::create([
            'user_id' => $employee->id,
            'worked_on' => today()->toDateString(),
            'task' => 'Shoot',
            'task_type' => 'shooting',
            'venture' => 'SVA Silks',
            'minutes' => 480,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($admin)->get(route('clients.show', $client));

        $response->assertOk();
        $timesheet = $response->viewData('timesheet');
        $this->assertSame(180, $timesheet['minutes']);
        $this->assertSame(2, $timesheet['entries']);
        $response->assertSee('3 hrs');
        $response->assertSee('Shooting');
        $response->assertDontSee('8 hrs'); // SVA hours must not appear as this client's total
    }

    public function test_shows_empty_state_when_the_client_has_no_invoices(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();

        $response = $this->actingAs($user)->get(route('clients.show', $client));

        $response->assertOk();
        $response->assertSee('No invoices for this client yet.');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $client = Client::factory()->create();

        $this->get(route('clients.show', $client))->assertRedirect(route('login'));
    }
}
