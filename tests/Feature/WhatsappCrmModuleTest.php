<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The `whatsapp-crm` module skeleton: the permission gate, the route, and the
 * sidebar link, wired end to end. GrantableModulesTest already covers this
 * module generically (it walks every entry in Permission::MODULES); this
 * file pins the module's own name and placeholder page down explicitly.
 */
class WhatsappCrmModuleTest extends TestCase
{
    use RefreshDatabase;

    private function employee(): User
    {
        return User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
    }

    public function test_an_ungranted_employee_is_refused_the_module(): void
    {
        $this->actingAs($this->employee())
            ->get(route('whatsapp-crm.index'))
            ->assertForbidden();
    }

    public function test_a_granted_employee_reaches_the_placeholder_page(): void
    {
        $user = $this->employee();
        $user->syncPermissions(['whatsapp-crm' => ['view']]);

        $this->actingAs($user->refresh())
            ->get(route('whatsapp-crm.index'))
            ->assertOk()
            ->assertSee('WhatsApp CRM');
    }

    public function test_a_guest_is_sent_to_login_rather_than_refused(): void
    {
        $this->get(route('whatsapp-crm.index'))->assertRedirect(route('login'));
    }

    public function test_the_sidebar_lists_the_module_only_for_someone_who_can_view_it(): void
    {
        $this->actingAs($this->employee())
            ->get(route('my.dashboard'))
            ->assertDontSee(route('whatsapp-crm.index'));

        $user = $this->employee();
        $user->syncPermissions(['whatsapp-crm' => ['view']]);

        $this->actingAs($user->refresh())
            ->get(route('my.dashboard'))
            ->assertSee(route('whatsapp-crm.index'));
    }

    public function test_the_admin_sidebar_also_lists_the_module(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertSee(route('whatsapp-crm.index'));
    }
}
