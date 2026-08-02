<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
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
