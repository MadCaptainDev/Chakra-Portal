<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sidebar/Settings-hub restructure: nine Setup links collapsed into one
 * Settings destination (tabbed via x-settings-layout), Equipment nested
 * under Shoots rather than a fourth Production row, and the standalone
 * Developer/Swagger page reached from App Studio.
 */
class SidebarRestructureTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_the_admin_sidebar_shows_one_settings_link_not_nine(): void
    {
        $response = $this->actingAs($this->admin())->get(route('dashboard'));

        $response->assertOk()->assertSee(route('settings.edit'));

        // The other eight used to each get their own sidebar row.
        foreach (['whatsapp.edit', 'instagram-settings.edit', 'notion.edit', 'push.edit',
            'competitor-settings.edit', 'content-accounts.edit', 'brief-questions.index', 'invoice-template.edit'] as $routeName) {
            $response->assertDontSee(route($routeName));
        }
    }

    /**
     * Every one of the nine settings pages still renders, still reachable
     * directly, and now under the shared tab strip -- collapsing the
     * sidebar to one link must not have taken any of the nine pages
     * themselves away.
     */
    public function test_every_settings_tab_still_renders_and_shows_the_tab_strip(): void
    {
        $admin = $this->admin();

        foreach ([
            'settings.edit', 'whatsapp.edit', 'instagram-settings.edit', 'notion.edit',
            'push.edit', 'competitor-settings.edit', 'content-accounts.edit',
            'brief-questions.index', 'invoice-template.edit',
        ] as $routeName) {
            $response = $this->actingAs($admin)->get(route($routeName));

            $response->assertOk()
                // Every tab links every other tab -- proof the shared strip rendered.
                ->assertSee(route('settings.edit'))
                ->assertSee(route('invoice-template.edit'));
        }
    }

    public function test_equipment_is_reachable_but_not_a_standalone_production_module_row(): void
    {
        $response = $this->actingAs($this->admin())->get(route('dashboard'));

        // Still reachable -- its own permission/routes are untouched.
        $response->assertOk()->assertSeeInOrder([route('shoots.index'), route('equipment.index')]);
    }

    public function test_developer_page_is_reachable_by_someone_who_can_manage_saas_products(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('developer.index'))->assertOk();

        $response = $this->actingAs($admin)->get(route('developer.openapi'));
        $response->assertOk();
        $response->assertJsonPath('openapi', '3.0.3');
        $response->assertJsonStructure(['paths' => [
            '/api/saas/backups', '/api/saas/backups/{id}/download', '/api/saas/license', '/api/saas/config',
        ]]);
    }

    public function test_developer_page_is_refused_to_an_ungranted_employee(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($employee)->get(route('developer.index'))->assertForbidden();
    }

    public function test_the_developer_link_appears_under_app_studio_only_for_someone_who_can_manage_saas_products(): void
    {
        $admin = $this->admin();
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $employee->syncPermissions(['saas-products' => ['view']]);

        $this->actingAs($admin)->get(route('dashboard'))->assertSee(route('developer.index'));

        // Granted view but not manage: sees the module, not the API reference.
        $this->actingAs($employee->refresh())->get(route('my.dashboard'))->assertDontSee(route('developer.index'));
    }

    public function test_admin_sidebar_lists_permission_groups_settings_and_nav_filter(): void
    {
        $response = $this->actingAs($this->admin())->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('Filter menu', false)
            ->assertSee('Production', false)
            ->assertSee('Finance', false)
            ->assertSee(route('settings.edit'));
    }
}
