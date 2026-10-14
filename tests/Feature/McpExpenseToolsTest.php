<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\McpToken;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The studio's own spending over MCP: what is due, logging a one-time spend,
 * and marking an EMI, salary or bill paid.
 */
class McpExpenseToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-09 11:00:00');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function employeeWith(array $grants): User
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        foreach ($grants as $module => $abilities) {
            foreach ($abilities as $ability) {
                UserPermission::create(['user_id' => $user->id, 'module' => $module, 'ability' => $ability]);
            }
        }

        return $user;
    }

    private function callTool(User $user, string $name, array $arguments = []): array
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.McpToken::issue($user, 'Test client')['plain']])
            ->postJson(route('mcp'), [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ])->assertOk()->json('result');
    }

    private function data(User $user, string $name, array $arguments = []): array
    {
        $result = $this->callTool($user, $name, $arguments);
        $this->assertArrayNotHasKey('isError', $result, 'Tool error: '.$result['content'][0]['text']);

        return json_decode($result['content'][0]['text'], true);
    }

    private function error(User $user, string $name, array $arguments = []): string
    {
        $result = $this->callTool($user, $name, $arguments);
        $this->assertTrue($result['isError'] ?? false, 'Expected a tool error');

        return $result['content'][0]['text'];
    }

    private function emi(array $overrides = []): Expense
    {
        return Expense::create($overrides + [
            'name' => 'HDFC camera loan', 'type' => Expense::TYPE_EMI, 'payee' => 'HDFC',
            'amount' => 12000, 'start_month' => '2026-08-01', 'installments' => 12, 'is_active' => true,
        ]);
    }

    private function salary(): Expense
    {
        return Expense::create([
            'name' => 'Sanjai', 'type' => Expense::TYPE_SALARY, 'amount' => 25000, 'is_active' => true,
        ]);
    }

    // ——— create_emi ———

    public function test_create_emi_adds_the_full_plan_on_the_existing_bank_spelling(): void
    {
        $this->emi(); // payee "HDFC"

        $data = $this->data($this->admin(), 'create_emi', [
            'name' => 'Sony 70-200 lens', 'bank_account' => 'hdfc', 'amount' => 4200,
            'tenure_months' => 12, 'start_month' => '2026-11', 'notes' => 'Loan a/c 1234',
        ]);

        $this->assertSame('HDFC', $data['bank_account']);
        $this->assertSame('2027-10', $data['last_month']);
        $this->assertEquals(50400, $data['total_payable']);

        $emi = Expense::findOrFail($data['expense_id']);
        $this->assertSame(Expense::TYPE_EMI, $emi->type);
        $this->assertSame('2026-11-01', $emi->start_month->toDateString());
        $this->assertSame(12, $emi->installments);
        $this->assertTrue($emi->isDueIn(Carbon::parse('2027-10-01')));
        $this->assertFalse($emi->isDueIn(Carbon::parse('2026-10-01')));
    }

    public function test_create_emi_files_installments_already_paid_for_a_running_loan(): void
    {
        $data = $this->data($this->admin(), 'create_emi', [
            'name' => 'Old gimbal', 'bank_account' => 'BOI', 'amount' => 2000,
            'tenure_months' => 6, 'start_month' => '2026-07', 'already_paid' => 3,
        ]);

        $this->assertSame(3, $data['installments_left']);
        $this->assertSame(['2026-07-01', '2026-08-01', '2026-09-01'],
            ExpensePayment::where('expense_id', $data['expense_id'])->orderBy('period')->pluck('period')
                ->map(fn ($p) => Carbon::parse($p)->toDateString())->all());

        // Only July to September fell before October.
        $this->assertStringContainsString('Only 3', $this->error($this->admin(), 'create_emi', [
            'name' => 'Other', 'bank_account' => 'BOI', 'amount' => 2000,
            'tenure_months' => 6, 'start_month' => '2026-07', 'already_paid' => 4,
        ]));
    }

    public function test_create_emi_refuses_a_running_duplicate_and_needs_the_create_permission(): void
    {
        $this->emi();

        $this->assertStringContainsString('already a running EMI', $this->error($this->admin(), 'create_emi', [
            'name' => 'hdfc camera loan', 'bank_account' => 'HDFC', 'amount' => 1, 'tenure_months' => 1,
        ]));

        $viewer = $this->employeeWith(['expenses' => ['view']]);
        $this->withHeaders(['Authorization' => 'Bearer '.McpToken::issue($viewer, 'Test client')['plain']])
            ->postJson(route('mcp'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => 'create_emi', 'arguments' => ['name' => 'X', 'bank_account' => 'Axis', 'amount' => 1, 'tenure_months' => 1]]])
            ->assertJsonPath('result', null);
        $this->assertSame(1, Expense::where('type', Expense::TYPE_EMI)->count());
    }

    // ——— log_expense ———

    public function test_a_one_time_expense_is_logged_as_paid_in_its_month(): void
    {
        $data = $this->data($this->admin(), 'log_expense', [
            'name' => 'Fuel for Ooty shoot', 'amount' => 2400, 'category' => 'fuel', 'payee' => 'Indian Oil',
        ]);

        $this->assertSame('Fuel', $data['category']);
        $this->assertSame('2026-10-09', $data['spent_on']);

        $expense = Expense::findOrFail($data['expense_id']);
        $this->assertSame(Expense::TYPE_OTHER, $expense->type);
        $this->assertDatabaseHas('expense_payments', ['expense_id' => $expense->id, 'amount_paid' => 2400]);
        $this->assertEquals(2400, $data['one_time_total_for_month']['total']);
    }

    public function test_log_expense_refuses_an_unknown_category_and_a_future_date(): void
    {
        $admin = $this->admin();

        $this->assertStringContainsString('Equipment', $this->error($admin, 'log_expense', ['name' => 'X', 'amount' => 10, 'category' => 'Snacks']));
        $this->assertStringContainsString('future', $this->error($admin, 'log_expense', ['name' => 'X', 'amount' => 10, 'category' => 'Fuel', 'spent_on' => '2026-10-20']));
        $this->assertSame(0, Expense::count());
    }

    // ——— record_expense_payment ———

    public function test_an_emi_is_marked_paid_at_its_standard_amount_by_name(): void
    {
        $emi = $this->emi();

        $data = $this->data($this->admin(), 'record_expense_payment', ['expense' => 'hdfc']);

        $this->assertSame('3 of 12', $data['installment']);
        $this->assertSame(12000.0, (float) $data['amount_paid']);
        $this->assertSame('paid', $data['status']);
        $this->assertSame(9, $data['installments_left_after_this_month']);
        $this->assertSame(108000.0, (float) $data['still_owed_on_loan']);
        $this->assertTrue($emi->isPaidInFullFor(Carbon::parse('2026-10-01')));
    }

    public function test_an_existing_payment_is_not_overwritten_unless_asked(): void
    {
        $emi = $this->emi();
        $admin = $this->admin();
        $this->data($admin, 'record_expense_payment', ['expense' => (string) $emi->id, 'amount' => 5000]);

        $message = $this->error($admin, 'record_expense_payment', ['expense' => (string) $emi->id, 'amount' => 7000]);
        $this->assertStringContainsString('already has ₹5,000.00', $message);
        $this->assertSame(5000.0, $emi->recordedPaidFor(Carbon::parse('2026-10-01')));

        $data = $this->data($admin, 'record_expense_payment', ['expense' => (string) $emi->id, 'amount' => 12000, 'replace_existing' => true]);
        $this->assertSame(5000.0, (float) $data['replaced_previous_amount']);
        $this->assertSame(1, ExpensePayment::count());
    }

    public function test_a_month_outside_the_emi_term_is_refused(): void
    {
        $emi = $this->emi();

        $message = $this->error($this->admin(), 'record_expense_payment', ['expense' => (string) $emi->id, 'month' => '2027-09']);

        $this->assertStringContainsString('no installment', $message);
        $this->assertSame(0, ExpensePayment::count());
    }

    public function test_an_ambiguous_name_lists_both_ids(): void
    {
        $a = $this->emi(['name' => 'HDFC camera loan']);
        $b = $this->emi(['name' => 'HDFC car loan']);

        $message = $this->error($this->admin(), 'record_expense_payment', ['expense' => 'HDFC']);

        $this->assertStringContainsString("id {$a->id}", $message);
        $this->assertStringContainsString("id {$b->id}", $message);
        $this->assertSame(0, ExpensePayment::count());
    }

    public function test_salaries_need_the_salaries_module(): void
    {
        $salary = $this->salary();
        $clerk = $this->employeeWith(['expenses' => ['view', 'edit']]);

        // Not resolvable, not listed -- as if it did not exist.
        $this->assertStringContainsString('No EMI, bill or salary', $this->error($clerk, 'record_expense_payment', ['expense' => 'Sanjai']));
        $due = $this->data($clerk, 'expenses_due');
        $this->assertFalse($due['salaries_included']);
        $this->assertNotContains($salary->id, array_column($due['items'], 'expense_id'));

        $payroll = $this->employeeWith(['expenses' => ['view', 'edit'], 'salaries' => ['view', 'edit']]);
        $data = $this->data($payroll, 'record_expense_payment', ['expense' => 'Sanjai', 'note' => 'UPI']);
        $this->assertSame('salary', $data['type']);
        $this->assertSame(25000.0, (float) $data['amount_paid']);
    }

    public function test_an_employee_without_expenses_access_gets_none_of_the_tools(): void
    {
        $names = collect(app(\App\Mcp\Server::class)->mcpToolsFor(User::factory()->create(['role' => User::ROLE_EMPLOYEE])))
            ->map->name();

        foreach (['expenses_due', 'log_expense', 'record_expense_payment'] as $name) {
            $this->assertNotContains($name, $names);
        }
    }

    // ——— expenses_due ———

    public function test_expenses_due_shows_status_and_totals(): void
    {
        $emi = $this->emi();
        $this->salary();
        $admin = $this->admin();
        $this->data($admin, 'record_expense_payment', ['expense' => (string) $emi->id]);

        $data = $this->data($admin, 'expenses_due', ['month' => '2026-10']);
        $this->assertSame(37000.0, (float) $data['total_due']);
        $this->assertSame(25000.0, (float) $data['still_to_pay']);

        $unpaid = $this->data($admin, 'expenses_due', ['unpaid_only' => true]);
        $this->assertSame(['Sanjai'], array_column($unpaid['items'], 'name'));
    }
}
