<?php

namespace Tests\Feature;

use App\Mcp\Server;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\McpCallLog;
use App\Models\McpToken;
use App\Models\Quotation;
use App\Models\Shoot;
use App\Models\User;
use App\Tools\Tool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The second wave of MCP tools (quotations, shoots, briefs, Instagram, money,
 * insights, ad reports), the call log, the locked Routines API and the
 * Developer space.
 */
class McpStudioToolsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function tokenFor(User $user): string
    {
        return McpToken::issue($user, 'Test client')['plain'];
    }

    private function rpc(string $token, array $message): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson(route('mcp'), $message);
    }

    private function callTool(string $token, string $name, array $arguments = []): array
    {
        return $this->rpc($token, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ])->assertOk()->json('result');
    }

    private function data(string $token, string $name, array $arguments = []): array
    {
        $result = $this->callTool($token, $name, $arguments);
        $this->assertArrayNotHasKey('isError', $result, 'Tool error: '.$result['content'][0]['text']);

        return json_decode($result['content'][0]['text'], true);
    }

    private function names(string $token): array
    {
        return collect($this->rpc($token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->json('result.tools'))
            ->pluck('name')->all();
    }

    // ——— Registration and descriptions ———

    public function test_every_tool_has_a_unique_name_a_real_description_and_a_closed_schema(): void
    {
        $tools = app(Server::class)->tools();
        $names = array_map(fn (Tool $t) => $t->name(), $tools);

        $this->assertSame(count($names), count(array_unique($names)), 'Duplicate tool names');

        foreach ($tools as $tool) {
            $this->assertGreaterThan(40, strlen($tool->description()), $tool->name().' needs a fuller description');
            $this->assertFalse($tool->schema()['additionalProperties'] ?? true, $tool->name().' must refuse unknown arguments');
        }
    }

    public function test_tools_that_message_a_client_say_so_in_the_description_and_the_annotations(): void
    {
        foreach (app(Server::class)->tools() as $tool) {
            if (! $tool->messagesClient()) {
                continue;
            }
            $this->assertStringContainsString('REAL CLIENT', $tool->description(), $tool->name());
            $this->assertTrue($tool->describe()['annotations']['openWorldHint']);
            $this->assertFalse($tool->describe()['annotations']['readOnlyHint']);
        }
    }

    public function test_an_admin_gets_the_new_tools(): void
    {
        $names = $this->names($this->tokenFor($this->admin()));

        foreach ([
            'create_quotation', 'send_quotation_whatsapp', 'create_shoot', 'assign_shoot_crew',
            'shoots_rolling_now', 'get_client_brief', 'send_brief_reminder', 'instagram_performance',
            'money_due', 'client_advances_owed', 'log_client_advance', 'needs_attention',
            'hours_vs_revenue', 'payment_behaviour', 'import_ad_report', 'send_ad_report', 'list_ad_reports',
        ] as $name) {
            $this->assertContains($name, $names);
        }
    }

    public function test_an_employee_with_no_permissions_gets_none_of_them(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $names = $this->names($this->tokenFor($employee));

        foreach (['create_quotation', 'money_due', 'log_client_advance', 'import_ad_report', 'create_shoot', 'get_client_brief'] as $name) {
            $this->assertNotContains($name, $names);
        }
    }

    public function test_the_whatsapp_assistant_only_gets_the_two_small_ones(): void
    {
        $admin = $this->admin();
        $assistant = collect(app(Server::class)->toolsFor($admin))
            ->reject(fn (Tool $t) => $t->mcpOnly())
            ->map(fn (Tool $t) => $t->name());

        $this->assertContains('money_due', $assistant);
        $this->assertContains('shoots_rolling_now', $assistant);
        $this->assertNotContains('create_quotation', $assistant);
        $this->assertNotContains('log_client_advance', $assistant);
    }

    // ——— Client resolution ———

    public function test_an_ambiguous_client_name_is_refused_with_both_ids(): void
    {
        $a = Client::create(['name' => 'Riya Makeover']);
        $b = Client::create(['name' => 'Riya Bakes']);

        $result = $this->callTool($this->tokenFor($this->admin()), 'get_client_brief', ['client' => 'Riya']);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString("id {$a->id}", $result['content'][0]['text']);
        $this->assertStringContainsString("id {$b->id}", $result['content'][0]['text']);
    }

    // ——— Quotations ———

    public function test_create_quotation_makes_a_numbered_draft_with_the_totals_worked_out(): void
    {
        $client = Client::create(['name' => 'Thillai Pets Clinic']);

        $data = $this->data($this->tokenFor($this->admin()), 'create_quotation', [
            'client' => 'Thillai',
            'items' => [
                ['description' => 'Reels', 'quantity' => 4, 'unit_price' => 5000],
                ['description' => 'Shoot day', 'quantity' => 1, 'unit_price' => 8000],
            ],
            'discount_label' => 'Launch',
            'discount_amount' => 1000,
        ]);

        $this->assertSame('Thillai Pets Clinic', $data['client']);
        $this->assertEquals(28000, $data['subtotal']);
        $this->assertEquals(27000, $data['total']);

        $quotation = Quotation::findOrFail($data['quotation_id']);
        $this->assertSame(Quotation::STATUS_DRAFT, $quotation->status);
        $this->assertSame($client->id, $quotation->client_id);
        $this->assertNotNull($quotation->quotation_number);
        $this->assertCount(2, $quotation->items);
    }

    public function test_create_quotation_refuses_a_bad_line_and_a_discount_without_a_label(): void
    {
        Client::create(['name' => 'Thillai Pets Clinic']);
        $token = $this->tokenFor($this->admin());

        $bad = $this->callTool($token, 'create_quotation', ['client' => 'Thillai', 'items' => [['description' => 'X', 'quantity' => 0, 'unit_price' => 10]]]);
        $this->assertTrue($bad['isError']);

        $noLabel = $this->callTool($token, 'create_quotation', [
            'client' => 'Thillai', 'items' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 10]], 'discount_amount' => 5,
        ]);
        $this->assertTrue($noLabel['isError']);
        $this->assertSame(0, Quotation::count());
    }

    // ——— Shoots ———

    public function test_create_shoot_and_assign_crew(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $aron = User::factory()->create(['name' => 'Aron Kumar', 'role' => User::ROLE_EMPLOYEE]);
        Client::create(['name' => 'SVA Ortho']);
        $token = $this->tokenFor($admin);

        $when = now()->addDays(3)->format('Y-m-d').' 09:30';
        $shoot = $this->data($token, 'create_shoot', ['title' => 'Clinic reels', 'starts_at' => $when, 'client' => 'SVA']);

        $this->assertSame('SVA Ortho', $shoot['client']);
        $this->assertSame($when, $shoot['starts_at']);

        $crew = $this->data($token, 'assign_shoot_crew', ['shoot_id' => $shoot['shoot_id'], 'person' => 'Aron', 'role' => 'Camera', 'call_time' => '08:45']);

        $this->assertSame('Aron Kumar', $crew['person']);
        $this->assertSame('added', $crew['result']);
        $this->assertTrue($crew['push_notification_sent']);
        $this->assertSame(1, Shoot::find($shoot['shoot_id'])->crew()->count());
    }

    public function test_create_shoot_refuses_a_loose_date(): void
    {
        $result = $this->callTool($this->tokenFor($this->admin()), 'create_shoot', ['title' => 'X', 'starts_at' => 'next friday']);

        $this->assertTrue($result['isError']);
        $this->assertSame(0, Shoot::count());
    }

    public function test_shoots_rolling_now_lists_only_started_unwrapped_shoots(): void
    {
        $admin = $this->admin();
        Shoot::create(['title' => 'Rolling', 'starts_at' => now(), 'status' => Shoot::STATUS_CONFIRMED])
            ->forceFill(['started_at' => now()->subHour(), 'started_by_id' => $admin->id])->save();
        Shoot::create(['title' => 'Planned', 'starts_at' => now()->addDay(), 'status' => Shoot::STATUS_PLANNED]);

        $data = $this->data($this->tokenFor($admin), 'shoots_rolling_now');

        $this->assertSame(1, $data['rolling']);
        $this->assertSame('Rolling', $data['shoots'][0]['title']);
    }

    // ——— Money ———

    public function test_money_due_lists_unpaid_invoices_worst_first(): void
    {
        $client = Client::create(['name' => 'Late Payer']);
        Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'due_date' => today()->subDays(40)]);
        Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'due_date' => today()->subDays(5)]);

        $data = $this->data($this->tokenFor($this->admin()), 'money_due');

        $this->assertSame(2, $data['unpaid_invoices']['count']);
        $this->assertSame(40, $data['unpaid_invoices']['items'][0]['days_overdue']);
    }

    public function test_log_client_advance_records_against_the_resolved_client(): void
    {
        $client = Client::create(['name' => 'Thillai Pets Clinic']);

        $data = $this->data($this->tokenFor($this->admin()), 'log_client_advance', [
            'client' => (string) $client->id, 'name' => 'October Meta Ads top-up', 'amount' => 5000,
        ]);

        $this->assertSame('Thillai Pets Clinic', $data['client'] ?? $data['logged']['client'] ?? null);
        $this->assertDatabaseHas('client_advances', ['client_id' => $client->id, 'name' => 'October Meta Ads top-up']);
    }

    // ——— Call log ———

    public function test_every_call_is_logged_with_its_outcome(): void
    {
        $admin = $this->admin();
        $token = $this->tokenFor($admin);

        $this->data($token, 'whoami');
        $this->callTool($token, 'create_shoot', ['title' => 'X', 'starts_at' => 'nonsense']);

        $this->assertSame(2, McpCallLog::count());
        $this->assertTrue(McpCallLog::where('tool', 'whoami')->first()->ok);

        $failed = McpCallLog::where('tool', 'create_shoot')->first();
        $this->assertFalse($failed->ok);
        $this->assertSame($admin->id, $failed->user_id);
        $this->assertNotNull($failed->mcp_token_id);
        $this->assertStringContainsString('nonsense', $failed->arguments);
    }

    // ——— Routines API ———

    public function test_the_routines_whatsapp_api_needs_a_token(): void
    {
        $this->postJson('/api/routines/whatsapp/send', ['message' => 'hi'])->assertUnauthorized();
        $this->postJson('/api/routines/whatsapp/send-to', ['to' => '9876543210', 'message' => 'hi'])->assertUnauthorized();
    }

    public function test_the_routines_whatsapp_api_refuses_a_non_admin_token(): void
    {
        $token = $this->tokenFor(User::factory()->create(['role' => User::ROLE_EMPLOYEE]));

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/routines/whatsapp/send-to', ['to' => '9876543210', 'message' => 'hi'])
            ->assertForbidden();
    }

    // ——— Developer space ———

    public function test_the_developer_space_shows_connect_tools_and_activity_to_an_admin(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('developer.index'))
            ->assertOk()
            ->assertSee(route('mcp'))
            ->assertSee('claude mcp add')
            ->assertSee('create_quotation')
            ->assertSee('Messages a client')
            ->assertSee('/api/routines/whatsapp/send');
    }

    public function test_the_developer_space_is_in_the_sidebar_for_an_admin_only(): void
    {
        $this->actingAs($this->admin())->get(route('dashboard'))->assertSee(route('developer.index'));

        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $this->actingAs($employee)->get(route('developer.index'))->assertForbidden();
    }

    public function test_a_granted_employee_sees_only_their_own_calls(): void
    {
        $admin = $this->admin();
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $employee->syncPermissions(['developer' => ['view']]);

        McpCallLog::record($admin, null, 'run_query', ['sql' => 'secret-admin-query'], true, null, 5);
        McpCallLog::record($employee, null, 'list_todos', [], true, null, 5);

        $this->actingAs($employee->refresh())->get(route('developer.index'))
            ->assertOk()
            ->assertSee('list_todos')
            ->assertDontSee('secret-admin-query');
    }
}
