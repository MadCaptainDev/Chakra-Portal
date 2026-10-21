<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalSectionGateTest extends TestCase
{
    use RefreshDatabase;

    private function loginFor(Client $client): User
    {
        return User::factory()->create(['role' => User::ROLE_CLIENT, 'client_id' => $client->id]);
    }

    public function test_a_section_the_studio_turned_off_is_a_404_for_that_client(): void
    {
        $client = Client::create(['name' => 'SVA', 'portal_sections_disabled' => ['invoices']]);

        $this->actingAs($this->loginFor($client))->get(route('client.invoices'))->assertNotFound();
    }

    public function test_a_section_left_on_still_opens(): void
    {
        $client = Client::create(['name' => 'SVA', 'portal_sections_disabled' => []]);

        $this->actingAs($this->loginFor($client))->get(route('client.invoices'))->assertOk();
    }

    public function test_nobody_has_chosen_yet_so_the_off_by_default_sections_are_closed(): void
    {
        $client = Client::create(['name' => 'SVA']);
        $user = $this->loginFor($client);

        $this->actingAs($user)->get(route('client.portfolio'))->assertNotFound();
        $this->actingAs($user)->get(route('client.invoices'))->assertOk();
    }
}
