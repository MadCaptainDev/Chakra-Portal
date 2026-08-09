<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    // The landing page reads the portfolio and team tables now that both are
    // managed from the admin panel, so it needs a schema to render.
    use RefreshDatabase;

    /**
     * The root URL now serves the public landing page rather than bouncing
     * straight to the login form. Full coverage lives in LandingPageTest.
     */
    public function test_root_url_serves_the_landing_page(): void
    {
        $this->get('/')->assertOk();
    }
}
