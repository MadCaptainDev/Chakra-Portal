<?php

namespace Tests\Feature;

use App\Support\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Areas that used to be admin-only and are now granted a module at a time.
 *
 * Two halves, and the second is the one that matters. Plenty of tests already
 * prove an ungranted employee is refused; these prove that ticking one box
 * actually lets them in, because a permission that grants nothing is the
 * failure mode nobody notices until somebody complains they still cannot work.
 */
class GrantableModulesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every module that has an index screen, which is every module the sidebar
     * can link to. Driven off the registry rather than a hand-written list, so
     * a module added later is covered by these tests the day it is added.
     *
     * @return array<string, array{0: string}>
     */
    public static function modules(): array
    {
        $cases = [];

        foreach (array_keys(Permission::MODULES) as $module) {
            $cases[$module] = [$module];
        }

        return $cases;
    }

    private function employee(): User
    {
        return User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
    }

    #[DataProvider('modules')]
    public function test_every_module_has_an_index_route_for_the_sidebar_to_link(string $module): void
    {
        /*
         * The sidebar hides any module without an index route rather than
         * linking into a 404. That guard is correct, but it also means a
         * missing route shows up as a permission that silently does nothing --
         * so it is asserted here instead of being discovered by a person.
         */
        $this->assertTrue(
            Route::has($module.'.index'),
            "Module [{$module}] is registered but has no {$module}.index route, so it can never appear in the sidebar."
        );
    }

    #[DataProvider('modules')]
    public function test_an_ungranted_employee_is_refused_every_module(string $module): void
    {
        $this->actingAs($this->employee())
            ->get(route($module.'.index'))
            ->assertForbidden();
    }

    #[DataProvider('modules')]
    public function test_granting_view_opens_that_module_and_nothing_else(string $module): void
    {
        $user = $this->employee();
        $user->syncPermissions([$module => ['view']]);

        $this->actingAs($user->refresh())
            ->get(route($module.'.index'))
            ->assertOk();

        // The whole point of granting one module is that it stays one module.
        foreach (array_keys(Permission::MODULES) as $other) {
            if ($other === $module) {
                continue;
            }

            $this->actingAs($user)
                ->get(route($other.'.index'))
                ->assertForbidden();
        }
    }

    #[DataProvider('modules')]
    public function test_every_module_declares_an_icon(string $module): void
    {
        // Without one the sidebar falls back to a generic glyph and every row
        // looks the same, which is the bug that moved icons into the registry.
        $this->assertArrayHasKey('icon', Permission::MODULES[$module]);
        $this->assertNotEmpty(Permission::MODULES[$module]['icon']);
    }

    public function test_the_money_screens_are_reachable_one_at_a_time(): void
    {
        $user = $this->employee();
        $user->syncPermissions(['expenses' => ['view']]);
        $user->refresh();

        // Expenses covers the month overview and its three sub-ledgers...
        foreach (['expenses.index', 'emi.index', 'bills.index', 'other.index'] as $route) {
            $this->actingAs($user)->get(route($route))->assertOk();
        }

        // ...but never payroll, which is the separation the split exists for.
        $this->actingAs($user)->get(route('salaries.index'))->assertForbidden();
    }

    public function test_paying_an_expense_needs_more_than_reading_one(): void
    {
        $user = $this->employee();
        $user->syncPermissions(['expenses' => ['view']]);

        $this->actingAs($user->refresh())
            ->post(route('expenses.pay-all'))
            ->assertForbidden();
    }

    public function test_approving_an_invoice_is_separate_from_editing_one(): void
    {
        $user = $this->employee();
        $user->syncPermissions(['invoices' => ['view', 'create', 'edit']]);
        $user->refresh();

        $this->actingAs($user)->get(route('invoices.create'))->assertOk();

        // Raising an invoice and signing it off are deliberately different
        // abilities, so a full editor still cannot approve.
        $this->assertFalse($user->can('invoices.approve'));
    }

    public function test_the_admin_only_areas_stay_admin_only(): void
    {
        $user = $this->employee();

        // Granting everything the registry offers must not open these.
        $user->syncPermissions(
            collect(Permission::MODULES)
                ->map(fn (array $config) => $config['abilities'])
                ->all()
        );
        $user->refresh();

        foreach (['/settings', '/whatsapp', '/users', '/invoice-template', '/editors'] as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }
    }
}
