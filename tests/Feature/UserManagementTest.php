<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_route_no_longer_exists(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
    }

    public function test_staff_can_add_a_new_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('users.store'), [
            'name' => 'New Staff',
            'email' => 'new-staff@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => \App\Models\User::ROLE_ADMIN,
        ]);

        $response->assertRedirect(route('users.index'));
        $this->assertDatabaseHas('users', [
            'email' => 'new-staff@example.com',
            'role' => \App\Models\User::ROLE_ADMIN,
        ]);
    }

    public function test_an_access_level_must_be_chosen(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('users.store'), [
            'name' => 'No Role',
            'email' => 'no-role@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'no-role@example.com']);
    }

    public function test_the_form_offers_an_admin_switch_rather_than_an_access_level(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)->get(route('users.create'));

        $response->assertOk()
            ->assertSee('Studio admin')
            // Access is the module matrix now; there is no ladder to pick from.
            ->assertDontSee('Access Level')
            // And the form has to say what an untouched account can already do,
            // or an empty matrix reads as "this person can do nothing".
            ->assertSee('Everyone gets')
            ->assertSee('Timesheet');
    }

    public function test_leaving_the_admin_switch_off_creates_an_employee(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        // What the form posts with the box unticked: the hidden field alone.
        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Plain Staff',
            'email' => 'plain@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => User::ROLE_EMPLOYEE,
        ])->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('users', [
            'email' => 'plain@example.com',
            'role' => User::ROLE_EMPLOYEE,
        ]);
    }

    public function test_managers_can_be_named_when_the_account_is_created(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $producer = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $lead = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Reports To Someone',
            'email' => 'reports@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => User::ROLE_EMPLOYEE,
            'manager_ids' => [$producer->id, $lead->id],
        ])->assertRedirect(route('users.index'));

        $created = User::where('email', 'reports@example.com')->firstOrFail();

        $this->assertEqualsCanonicalizing(
            [$producer->id, $lead->id],
            $created->managers->pluck('id')->all()
        );
    }

    public function test_naming_the_same_manager_twice_leaves_one_row(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Reports To Someone',
            'email' => 'twice@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => User::ROLE_EMPLOYEE,
            'manager_ids' => [$manager->id, $manager->id],
        ])->assertSessionHasNoErrors();

        $this->assertCount(1, User::where('email', 'twice@example.com')->firstOrFail()->managers);
    }

    public function test_staff_can_remove_another_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $response = $this->actingAs($user)->delete(route('users.destroy', $other));

        $response->assertRedirect(route('users.index'));
        $this->assertDatabaseMissing('users', ['id' => $other->id]);
    }

    public function test_cannot_delete_own_account_from_users_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->delete(route('users.destroy', $user));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_cannot_delete_a_user_who_created_invoices(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Invoice::factory()->create(['created_by' => $other->id]);

        $response = $this->actingAs($user)->delete(route('users.destroy', $other));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('users', ['id' => $other->id]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('users.index'))->assertRedirect(route('login'));
    }
}
