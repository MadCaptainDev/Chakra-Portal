<?php

namespace Tests\Feature;

use App\Models\Script;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The permission system's job is to widen access one module at a time without
 * ever widening it anywhere else. Most of these tests are about the "anywhere
 * else".
 */
class ModulePermissionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function employee(array $overrides = []): User
    {
        return User::factory()->create($overrides + ['role' => User::ROLE_EMPLOYEE]);
    }

    /** An employee granted specific abilities on Scripts — Kanishka. */
    private function writer(array $abilities = ['view', 'create', 'edit', 'comment']): User
    {
        $user = $this->employee();
        $user->syncPermissions(['scripts' => $abilities]);

        return $user->refresh();
    }

    private function script(array $overrides = []): Script
    {
        return Script::create($overrides + [
            'title' => 'Tea montage reel',
            'status' => Script::STATUS_DRAFT,
            'priority' => Script::PRIORITY_NORMAL,
        ]);
    }

    public function test_a_plain_employee_holds_no_permissions_and_is_refused_scripts(): void
    {
        $this->actingAs($this->employee())->get(route('scripts.index'))->assertForbidden();
    }

    public function test_a_granted_writer_reaches_the_scripts_list(): void
    {
        $this->actingAs($this->writer())->get(route('scripts.index'))->assertOk();
    }

    /**
     * The crown jewel. Granting Scripts must not become a side door into the
     * books — this walks the same 15 URLs EmployeeAccessTest guards.
     *
     * @dataProvider \Tests\Feature\EmployeeAccessTest::adminRoutes
     */
    public function test_a_granted_writer_is_still_refused_every_admin_area(string $url): void
    {
        $this->actingAs($this->writer())->get($url)->assertForbidden();
    }

    public function test_view_alone_does_not_permit_creating(): void
    {
        $writer = $this->writer(['view']);

        $this->actingAs($writer)->get(route('scripts.create'))->assertForbidden();
        $this->actingAs($writer)->post(route('scripts.store'), [
            'title' => 'Sneaked in',
            'status' => Script::STATUS_DRAFT,
            'priority' => Script::PRIORITY_NORMAL,
        ])->assertForbidden();

        $this->assertDatabaseCount('scripts', 0);
    }

    public function test_view_alone_does_not_permit_editing(): void
    {
        $script = $this->script();

        $this->actingAs($this->writer(['view']))
            ->get(route('scripts.edit', $script))
            ->assertForbidden();
    }

    public function test_view_alone_does_not_permit_deleting(): void
    {
        $script = $this->script();

        $this->actingAs($this->writer(['view', 'edit']))
            ->delete(route('scripts.destroy', $script))
            ->assertForbidden();

        $this->assertDatabaseHas('scripts', ['id' => $script->id]);
    }

    public function test_a_view_only_writer_can_still_read_a_script(): void
    {
        $script = $this->script();

        $this->actingAs($this->writer(['view']))
            ->get(route('scripts.show', $script))
            ->assertOk()
            ->assertSee('Tea montage reel');
    }

    public function test_an_admin_needs_no_grants_at_all(): void
    {
        $admin = $this->admin();

        $this->assertDatabaseCount('user_permissions', 0);

        $this->actingAs($admin)->get(route('scripts.index'))->assertOk();
        $this->actingAs($admin)->get(route('scripts.create'))->assertOk();
        $this->assertTrue($admin->can('scripts.delete'));
    }

    public function test_manage_implies_the_other_abilities_in_its_own_module_only(): void
    {
        $user = $this->writer(['manage']);

        $this->assertTrue($user->can('scripts.delete'));
        $this->assertTrue($user->can('scripts.approve'));

        // And nothing outside it: the admin areas stay shut.
        $this->actingAs($user)->get('/invoices')->assertForbidden();
    }

    public function test_revoking_a_permission_takes_effect_immediately(): void
    {
        $user = $this->writer(['view']);
        $this->assertTrue($user->can('scripts.view'));

        $user->syncPermissions([]);

        $this->assertFalse($user->refresh()->can('scripts.view'));
        $this->actingAs($user)->get(route('scripts.index'))->assertForbidden();
    }

    public function test_an_unregistered_module_or_ability_cannot_be_granted(): void
    {
        $user = $this->employee();

        $user->syncPermissions([
            'finance' => ['view'],          // module does not exist
            'scripts' => ['superuser'],     // ability does not exist
        ]);

        $this->assertDatabaseCount('user_permissions', 0);
        $this->assertFalse($user->refresh()->can('scripts.view'));
    }

    public function test_the_admin_form_refuses_to_invent_a_module(): void
    {
        $admin = $this->admin();
        $target = $this->employee();

        $this->actingAs($admin)->put(route('users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'role' => User::ROLE_EMPLOYEE,
            'permissions' => ['finance' => ['manage']],
        ])->assertRedirect();

        $this->assertDatabaseCount('user_permissions', 0);
    }

    public function test_promoting_someone_to_admin_clears_their_grants(): void
    {
        $admin = $this->admin();
        $writer = $this->writer();

        $this->assertDatabaseCount('user_permissions', 4);

        $this->actingAs($admin)->put(route('users.update', $writer), [
            'name' => $writer->name,
            'email' => $writer->email,
            'role' => User::ROLE_ADMIN,
            'permissions' => ['scripts' => ['view', 'create', 'edit', 'comment']],
        ])->assertRedirect();

        // Dead weight while they are an admin, and a silent re-grant if they
        // were ever demoted.
        $this->assertDatabaseCount('user_permissions', 0);
    }

    public function test_grants_go_when_the_login_does(): void
    {
        $writer = $this->writer();
        $this->assertDatabaseCount('user_permissions', 4);

        $writer->delete();

        $this->assertDatabaseCount('user_permissions', 0);
    }

    public function test_permissions_cannot_be_mass_assigned(): void
    {
        $user = User::create([
            'name' => 'Sneaky',
            'email' => 'sneaky@example.com',
            'password' => 'password',
            'role' => User::ROLE_EMPLOYEE,
            'permissions' => ['scripts' => ['manage']],
        ]);

        $this->assertDatabaseCount('user_permissions', 0);
        $this->assertFalse($user->refresh()->can('scripts.manage'));
    }

    public function test_a_guest_is_sent_to_login_rather_than_refused(): void
    {
        $this->get(route('scripts.index'))->assertRedirect(route('login'));
    }

    public function test_the_sidebar_lists_a_module_only_for_someone_who_can_view_it(): void
    {
        // The employee's own dashboard renders the sidebar.
        $this->actingAs($this->employee())
            ->get(route('my.dashboard'))
            ->assertDontSee(route('scripts.index'));

        $this->actingAs($this->writer())
            ->get(route('my.dashboard'))
            ->assertSee(route('scripts.index'));
    }

    public function test_a_module_row_is_unique_per_ability(): void
    {
        $user = $this->employee();

        // Two syncs in a row must not accumulate duplicates.
        $user->syncPermissions(['scripts' => ['view', 'view', 'edit']]);
        $user->syncPermissions(['scripts' => ['view', 'edit']]);

        $this->assertSame(2, UserPermission::where('user_id', $user->id)->count());
    }
}
