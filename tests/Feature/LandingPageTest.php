<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_see_the_studio_landing_page(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Chakra Productions');

        // The sections the page is built around.
        $response->assertSee('What we do');
        $response->assertSee('How we work');
        $response->assertSee('Get in touch');
    }

    public function test_landing_page_links_to_staff_login(): void
    {
        // Sign-in is no longer the point of the page, but staff still need it.
        $this->get('/')
            ->assertSee(route('login'), false)
            ->assertSee('Staff sign in');
    }

    public function test_landing_page_carries_an_enquiry_form(): void
    {
        $this->get('/')
            ->assertSee(route('enquiry.store'), false)
            ->assertSee('Send enquiry');
    }

    public function test_work_section_is_hidden_while_the_showreel_is_empty(): void
    {
        // $showreel ships empty; an empty portfolio grid must never go public.
        $this->get('/')->assertDontSee('Selected work');
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

        // Nor anything about the internal portal's modules.
        $response->assertDontSee('Recurring billing');
        $response->assertDontSee('Salaries');
        $response->assertDontSee('Payroll');
    }
}
