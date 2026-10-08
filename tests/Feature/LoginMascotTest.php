<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginMascotTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_mascot_lives_on_the_sign_in_page_not_the_homepage(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertSee('loginMascot({ failed: false })', false)
            ->assertSee('images/mascot/chakra-directions.webp', false)
            ->assertSee('images/mascot/chakra-reactions.webp', false);

        $this->get('/')->assertOk()->assertDontSee('mascot', false);
    }

    public function test_a_failed_sign_in_tells_the_mascot(): void
    {
        $this->from(route('login'))
            ->post(route('login'), ['email' => 'nobody@example.com', 'password' => 'wrong'])
            ->assertRedirect(route('login'));

        $this->get(route('login'))->assertSee('loginMascot({ failed: true })', false);
    }
}
