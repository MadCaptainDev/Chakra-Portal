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
