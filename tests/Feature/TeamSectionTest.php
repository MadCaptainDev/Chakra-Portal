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

    public function test_an_admin_adds_someone_to_the_website_team(): void
    {
        $this->actingAs($this->admin())->post(route('team.store'), [
            'name' => 'Kavya R',
            'role' => 'Lead Editor',
            'bio' => 'Cuts the long-form work.',
            'sort_order' => 1,
            'is_visible' => '1',
            'photo' => UploadedFile::fake()->image('kavya.jpg', 600, 600),
        ])->assertRedirect(route('team.index'));

        $member = TeamMember::firstOrFail();
        $this->written[] = $member->photo_path;

        $this->assertSame('Kavya R', $member->name);
        $this->assertSame('Lead Editor', $member->role);
        $this->assertTrue($member->is_visible);
        $this->assertStringStartsWith('uploads/team/', $member->photo_path);
        $this->assertFileExists(public_path($member->photo_path));
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

    public function test_removing_someone_deletes_their_photo(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('team.store'), [
            'name' => 'Temporary',
            'is_visible' => '1',
            'photo' => UploadedFile::fake()->image('temp.jpg'),
        ]);

        $member = TeamMember::firstOrFail();
        $photo = $member->photo_path;

        $this->actingAs($admin)->delete(route('team.destroy', $member))->assertRedirect(route('team.index'));

        $this->assertSame(0, TeamMember::count());
        $this->assertFileDoesNotExist(public_path($photo));
    }

    public function test_staff_names_are_offered_as_suggestions_but_no_pay_detail_is(): void
    {
        Expense::create([
            'name' => 'Kavya R',
            'type' => Expense::TYPE_SALARY,
            'role' => 'Editor',
            'amount' => 32000,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->get(route('team.index'))
            ->assertOk()
            ->assertSee('Kavya R')
            ->assertDontSee('32000')
            ->assertDontSee('32,000');
    }

    public function test_employees_cannot_manage_the_team(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($employee)->get(route('team.index'))->assertForbidden();
        $this->actingAs($employee)->post(route('team.store'), ['name' => 'Nope'])->assertForbidden();
    }
}
