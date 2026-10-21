<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class TeamSectionTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            if (is_file(public_path($path))) {
                unlink(public_path($path));
            }
        }

        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }


    public function test_the_landing_page_shows_the_selected_team_in_order(): void
    {
        TeamMember::create(['name' => 'Second Person', 'role' => 'Camera', 'sort_order' => 2, 'is_visible' => true]);
        TeamMember::create(['name' => 'First Person', 'role' => 'Director', 'sort_order' => 1, 'is_visible' => true]);
        TeamMember::create(['name' => 'Hidden Person', 'role' => 'Intern', 'sort_order' => 3, 'is_visible' => false]);

        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('Who you work with')
            ->assertSee('First Person')
            ->assertSee('Second Person')
            ->assertDontSee('Hidden Person');

        $body = $response->getContent();
        $this->assertLessThan(
            strpos($body, 'Second Person'),
            strpos($body, 'First Person'),
            'Sort order decides who appears first.'
        );
    }

    public function test_the_team_section_is_hidden_while_nobody_is_published(): void
    {
        $this->get('/')->assertOk()->assertDontSee('Who you work with');
    }



}
