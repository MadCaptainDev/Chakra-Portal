<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The root URL now serves the public landing page rather than bouncing
     * straight to the login form. Full coverage lives in LandingPageTest.
     */
    public function test_root_url_serves_the_landing_page(): void
    {
        $this->get('/')->assertOk();
    }
}
