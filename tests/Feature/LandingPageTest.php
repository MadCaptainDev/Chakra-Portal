<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_see_the_landing_page(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Chakra-Portal');
        $response->assertSee('Sign In');
        // The modules it advertises.
        $response->assertSee('Invoices');
        $response->assertSee('Salaries');
        $response->assertSee('EMI');
    }

    public function test_landing_page_links_to_login(): void
    {
        $this->get('/')->assertSee(route('login'), false);
    }

    public function test_signed_in_staff_go_straight_to_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertRedirect(route('dashboard'));
    }

    public function test_landing_page_exposes_no_business_data(): void
    {
        // It is public: nothing about clients, money or staff belongs here.
        $response = $this->get('/');

        $response->assertDontSee('Kanishka');
        $response->assertDontSee('Thor Gym');
        $response->assertDontSee('ytkvasan@gmail.com');
    }
}
