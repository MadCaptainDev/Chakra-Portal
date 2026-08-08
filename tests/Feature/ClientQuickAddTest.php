<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientQuickAddTest extends TestCase
{
    use RefreshDatabase;

    public function test_quick_store_creates_a_client_and_returns_it_as_json(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('clients.quick-store'), [
            'name' => 'Acme Media',
            'address' => '12 Mount Road, Chennai',
            'email' => 'billing@acme.test',
            'phone' => '9876543210',
        ]);

        $response->assertCreated();
        $response->assertJson([
            'name' => 'Acme Media',
            'address' => '12 Mount Road, Chennai',
            'email' => 'billing@acme.test',
            'phone' => '9876543210',
        ]);

        // The picker needs the id to select the new option.
        $this->assertSame(
            Client::where('name', 'Acme Media')->value('id'),
            $response->json('id')
        );
    }

    public function test_quick_store_returns_field_errors_as_json(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('clients.quick-store'), [
            'name' => '',
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'email']);
        $this->assertSame(0, Client::count());
    }

    public function test_quick_update_edits_the_selected_client(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create(['name' => 'Old Name', 'phone' => null]);

        $response = $this->actingAs($user)->postJson(route('clients.quick-update', $client), [
            'name' => 'New Name',
            'phone' => '9000000000',
        ]);

        $response->assertOk();
        $response->assertJson(['id' => $client->id, 'name' => 'New Name', 'phone' => '9000000000']);

        $client->refresh();
        $this->assertSame('New Name', $client->name);
        $this->assertSame('9000000000', $client->phone);
    }

    public function test_quick_endpoints_are_behind_auth(): void
    {
        $client = Client::factory()->create();

        $this->post(route('clients.quick-store'), ['name' => 'Sneaky'])
            ->assertRedirect(route('login'));
        $this->post(route('clients.quick-update', $client), ['name' => 'Sneaky'])
            ->assertRedirect(route('login'));

        $this->assertSame(0, Client::where('name', 'Sneaky')->count());
    }

    public function test_invoice_form_offers_the_client_modal_instead_of_a_page_away(): void
    {
        $user = User::factory()->create();
        Client::factory()->create(['name' => 'Acme Media']);

        $response = $this->actingAs($user)->get(route('invoices.create'));

        $response->assertOk();
        $response->assertSee('client-quick-form');
        $response->assertSee('clientPicker(');
        // The old flow sent people away and told them to come back.
        $response->assertDontSee('then refresh this page');
    }

    public function test_edit_form_renders_the_picker_with_the_invoice_client_selected(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create(['name' => 'Acme Media']);
        $invoice = Invoice::factory()->create(['client_id' => $client->id]);

        $response = $this->actingAs($user)->get(route('invoices.edit', $invoice));

        $response->assertOk();
        $response->assertSee('clientPicker(');
        // Js::from renders a single-quoted JS literal inside the x-data attribute.
        $response->assertSee("selectedId: '{$client->id}'", false);
    }
}
