<?php

namespace Tests\Feature;

use App\Models\McpCallLog;
use App\Models\McpOauthClient;
use App\Models\McpToken;
use App\Models\McpUserLimit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Per-person MCP limits set on Developer → Limits: access on/off, calls per
 * day, read-only, and whether client-messaging tools are offered.
 */
class McpUserLimitTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function rpc(User $user, string $method, array $params = []): TestResponse
    {
        $token = McpToken::issue($user, 'Test')['plain'];

        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson(route('mcp'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
    }

    private function toolNames(User $user): array
    {
        return collect($this->rpc($user, 'tools/list')->json('result.tools'))->pluck('name')->all();
    }

    public function test_by_default_nothing_is_limited(): void
    {
        $admin = $this->admin();

        $this->assertTrue(McpUserLimit::for($admin)->isDefault());
        $this->assertContains('create_quotation', $this->toolNames($admin));
        $this->assertContains('send_quotation_whatsapp', $this->toolNames($admin));
    }

    public function test_switching_access_off_blocks_every_token(): void
    {
        $user = $this->admin();
        McpUserLimit::create(['user_id' => $user->id, 'enabled' => false]);

        $this->rpc($user, 'ping')->assertForbidden()->assertJsonPath('error.message', fn ($m) => str_contains($m, 'turned off'));
    }

    public function test_switching_access_off_also_blocks_connecting_a_new_app(): void
    {
        $user = $this->admin();
        McpUserLimit::create(['user_id' => $user->id, 'enabled' => false]);
        $client = McpOauthClient::create(['client_id' => 'mcp_x', 'name' => 'Claude', 'redirect_uris' => ['https://claude.ai/cb']]);

        $this->actingAs($user)->get(route('mcp.oauth.authorize', [
            'response_type' => 'code', 'client_id' => $client->client_id, 'redirect_uri' => 'https://claude.ai/cb',
            'code_challenge' => 'abc', 'code_challenge_method' => 'S256',
        ]))->assertOk()->assertSee('turned off AI app access');
    }

    public function test_read_only_hides_every_tool_that_changes_data(): void
    {
        $user = $this->admin();
        McpUserLimit::create(['user_id' => $user->id, 'read_only' => true]);

        $names = $this->toolNames($user);

        $this->assertContains('money_due', $names);
        $this->assertNotContains('create_quotation', $names);
        $this->assertNotContains('log_client_advance', $names);
        $this->assertNotContains('send_quotation_whatsapp', $names);

        $this->rpc($user, 'tools/call', ['name' => 'create_shoot', 'arguments' => []])
            ->assertJsonPath('error.message', 'No such tool: create_shoot');
    }

    public function test_no_client_messages_hides_only_the_sending_tools(): void
    {
        $user = $this->admin();
        McpUserLimit::create(['user_id' => $user->id, 'can_message_clients' => false]);

        $names = $this->toolNames($user);

        $this->assertContains('create_quotation', $names);
        $this->assertNotContains('send_quotation_whatsapp', $names);
        $this->assertNotContains('send_ad_report', $names);
        $this->assertNotContains('send_brief_reminder', $names);
    }

    public function test_the_daily_limit_stops_calls_once_reached(): void
    {
        $user = $this->admin();
        McpUserLimit::create(['user_id' => $user->id, 'daily_limit' => 2]);

        $this->rpc($user, 'tools/call', ['name' => 'whoami'])->assertJsonMissingPath('result.isError');
        $this->rpc($user, 'tools/call', ['name' => 'whoami'])->assertJsonMissingPath('result.isError');
        $third = $this->rpc($user, 'tools/call', ['name' => 'whoami'])->assertOk();

        $this->assertTrue($third->json('result.isError'));
        $this->assertStringContainsString('Daily limit reached', $third->json('result.content.0.text'));
        $this->assertSame(2, McpCallLog::where('user_id', $user->id)->count());
    }

    public function test_yesterdays_calls_do_not_count(): void
    {
        $user = $this->admin();
        McpUserLimit::create(['user_id' => $user->id, 'daily_limit' => 1]);
        McpCallLog::record($user, null, 'whoami', [], true, null, 1);
        McpCallLog::query()->update(['created_at' => now()->subDay()]);

        $this->rpc($user, 'tools/call', ['name' => 'whoami'])->assertJsonMissingPath('result.isError');
    }

    public function test_an_admin_sets_limits_on_the_limits_tab(): void
    {
        $admin = $this->admin();
        $aron = User::factory()->create(['name' => 'Aron', 'role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($admin)->get(route('developer.index', ['tab' => 'limits']))
            ->assertOk()->assertSee('Aron')->assertSee('Calls per day');

        $this->actingAs($admin)->put(route('developer.limits.update', $aron), [
            'enabled' => '1', 'read_only' => '1', 'daily_limit' => '50',
        ])->assertRedirect(route('developer.index', ['tab' => 'limits']));

        $limit = McpUserLimit::for($aron);
        $this->assertTrue($limit->enabled);
        $this->assertTrue($limit->read_only);
        $this->assertFalse($limit->can_message_clients);
        $this->assertSame(50, $limit->daily_limit);
        $this->assertSame($admin->id, $limit->updated_by_id);
    }

    public function test_a_non_admin_cannot_change_limits_even_with_developer_access(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $employee->syncPermissions(['developer' => ['view']]);
        McpUserLimit::create(['user_id' => $employee->id, 'daily_limit' => 5]);

        $this->actingAs($employee->refresh())->put(route('developer.limits.update', $employee), ['enabled' => '1'])
            ->assertForbidden();

        $this->assertSame(5, McpUserLimit::for($employee)->daily_limit);

        $this->actingAs($employee)->get(route('developer.index', ['tab' => 'limits']))
            ->assertOk()->assertSee('Your limits')->assertSee('0 used of 5');
    }

    public function test_client_logins_cannot_be_given_limits(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);

        $this->actingAs($this->admin())->put(route('developer.limits.update', $client), ['enabled' => '1'])->assertNotFound();
    }
}
