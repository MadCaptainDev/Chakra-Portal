<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get(route('clients.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_client_without_invoices_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();

        $response = $this->actingAs($user)->delete(route('clients.destroy', $client));

        $response->assertRedirect(route('clients.index'));
        $this->assertDatabaseMissing('clients', ['id' => $client->id]);
    }

    public function test_client_with_invoices_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();
        Invoice::factory()->create(['client_id' => $client->id]);

        $response = $this->actingAs($user)->delete(route('clients.destroy', $client));

        $response->assertRedirect(route('clients.index'));
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('clients', ['id' => $client->id]);
    }

    public function test_a_fresh_client_is_active_by_default(): void
    {
        $client = Client::factory()->create();

        $this->assertTrue($client->is_active);
    }

    public function test_the_index_defaults_to_active_only(): void
    {
        Client::factory()->create(['name' => 'Running Client']);
        Client::factory()->create(['name' => 'Stopped Client', 'is_active' => false]);

        $response = $this->actingAs(User::factory()->create())->get(route('clients.index'));

        $response->assertOk();
        $response->assertSee('Running Client');
        $response->assertDontSee('Stopped Client');
    }

    public function test_the_inactive_tab_shows_only_inactive_clients(): void
    {
        Client::factory()->create(['name' => 'Running Client']);
        Client::factory()->create(['name' => 'Stopped Client', 'is_active' => false]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('clients.index', ['status' => 'inactive']));

        $response->assertOk();
        $response->assertSee('Stopped Client');
        $response->assertDontSee('Running Client');
    }

    public function test_the_all_tab_shows_both(): void
    {
        Client::factory()->create(['name' => 'Running Client']);
        Client::factory()->create(['name' => 'Stopped Client', 'is_active' => false]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('clients.index', ['status' => 'all']));

        $response->assertOk();
        $response->assertSee('Running Client');
        $response->assertSee('Stopped Client');
    }

    public function test_a_client_can_be_marked_inactive_through_the_edit_form(): void
    {
        $client = Client::factory()->create(['is_active' => true]);

        $this->actingAs(User::factory()->create())
            ->put(route('clients.update', $client), [
                'name' => $client->name,
                'client_type' => \App\Models\Client::CLIENT_TYPE_REGULAR,
                // The real form sends this as "0" for an unticked box (a
                // hidden input ahead of the checkbox, same pattern as
                // salaries/_form.blade.php) -- never genuinely absent, so
                // that is what a real unticked submission looks like.
                'is_active' => '0',
            ])
            ->assertRedirect();

        $this->assertFalse($client->fresh()->is_active);
    }

    /**
     * quickUpdate() (the invoice modal's lightweight edit) never renders an
     * is_active field at all -- a real absence, unlike the full form's
     * hidden-0-then-checkbox. That must not silently reactivate a client
     * someone deliberately marked inactive.
     */
    public function test_a_quick_update_does_not_silently_reactivate_an_inactive_client(): void
    {
        $client = Client::factory()->create(['is_active' => false]);

        $this->actingAs(User::factory()->create())
            ->post(route('clients.quick-update', $client), ['name' => 'New Name'])
            ->assertOk();

        $this->assertFalse($client->fresh()->is_active);
    }
}
