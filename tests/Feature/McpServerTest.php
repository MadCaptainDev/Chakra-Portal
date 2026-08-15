<?php

namespace Tests\Feature;

use App\Mcp\Protocol;
use App\Models\McpToken;
use App\Models\Todo;
use App\Models\TimesheetDay;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Models\UserPermission;
use App\Support\TimesheetVenture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class McpServerTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return McpToken::issue($user, 'Test client')['plain'];
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function rpc(string $token, array $message): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson(route('mcp'), $message);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function callTool(string $token, string $name, array $arguments = []): array
    {
        $response = $this->rpc($token, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ])->assertOk();

        return $response->json('result');
    }

    /**
     * The tool's payload, decoded back out of the text block it travels in.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function toolData(string $token, string $name, array $arguments = []): array
    {
        $result = $this->callTool($token, $name, $arguments);

        $this->assertArrayNotHasKey('isError', $result, 'Tool returned an error: '.$result['content'][0]['text']);

        return json_decode($result['content'][0]['text'], true);
    }

    // ——— The way in ———

    public function test_a_request_without_a_token_is_refused(): void
    {
        $this->postJson(route('mcp'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer realm="Chakra Portal"');
    }

    public function test_a_made_up_token_is_refused(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer chakra_'.str_repeat('x', 40)])
            ->postJson(route('mcp'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
            ->assertUnauthorized();
    }

    public function test_a_revoked_token_stops_working_immediately(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        $this->rpc($token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertOk();

        $user->mcpTokens()->delete();

        $this->rpc($token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertUnauthorized();
    }

    public function test_deleting_an_account_takes_its_tokens_with_it(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        $user->delete();

        $this->rpc($token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertUnauthorized();
    }

    public function test_only_the_hash_of_a_token_is_ever_stored(): void
    {
        $user = User::factory()->create();
        $plain = $this->tokenFor($user);

        // The row is a working login if this is ever false.
        $this->assertDatabaseMissing('mcp_tokens', ['token_hash' => $plain]);
        $this->assertDatabaseHas('mcp_tokens', ['token_hash' => hash('sha256', $plain)]);
    }

    public function test_a_request_from_another_site_is_refused(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        // The DNS-rebinding guard: a browser always sends Origin, a CLI never
        // does, so a page elsewhere cannot post here even holding a token.
        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Origin' => 'https://evil.example'])
            ->postJson(route('mcp'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
            ->assertForbidden();
    }

    public function test_the_endpoint_never_answers_a_get(): void
    {
        // Nothing streams and no session is kept, so GET would be a lie.
        $this->get('/mcp')->assertMethodNotAllowed();
    }

    // ——— The handshake ———

    public function test_initialize_answers_with_tools_and_nothing_else(): void
    {
        $token = $this->tokenFor(User::factory()->create());

        $result = $this->rpc($token, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => []],
        ])->assertOk()->json('result');

        $this->assertSame('2025-06-18', $result['protocolVersion']);
        $this->assertSame(['tools'], array_keys($result['capabilities']));
        $this->assertSame('chakra-portal', $result['serverInfo']['name']);
    }

    public function test_an_unknown_protocol_version_gets_our_newest(): void
    {
        $token = $this->tokenFor(User::factory()->create());

        $result = $this->rpc($token, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2099-01-01'],
        ])->assertOk()->json('result');

        // Answering in ours beats refusing to talk to a client that is merely
        // newer than we are.
        $this->assertSame(Protocol::VERSIONS[0], $result['protocolVersion']);
    }

    public function test_a_notification_gets_no_answer(): void
    {
        $token = $this->tokenFor(User::factory()->create());

        // No id. Answering notifications/initialized is a protocol violation.
        $this->rpc($token, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])
            ->assertNoContent(202);
    }

    public function test_an_unknown_method_is_a_protocol_error(): void
    {
        $token = $this->tokenFor(User::factory()->create());

        $this->rpc($token, ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'nonsense/doThing'])
            ->assertOk()
            ->assertJsonPath('id', 7)
            ->assertJsonPath('error.code', Protocol::METHOD_NOT_FOUND);
    }

    public function test_a_body_that_is_not_json_is_a_parse_error(): void
    {
        $token = $this->tokenFor(User::factory()->create());

        $this->call('POST', route('mcp'), [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'CONTENT_TYPE' => 'application/json',
        ], 'not json at all')
            ->assertStatus(400)
            ->assertJsonPath('error.code', Protocol::PARSE_ERROR);
    }

    public function test_a_batch_is_still_answered(): void
    {
        $token = $this->tokenFor(User::factory()->create());

        // Dropped from the newest revision, still sent by older clients.
        $this->rpc($token, [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'],
        ])->assertOk()->assertJsonCount(2);
    }

    // ——— Which tools a person is shown ———

    public function test_module_tools_are_hidden_from_someone_without_the_permission(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $names = $this->toolNames($this->tokenFor($employee));

        // Shown a tool they cannot use, a model keeps trying it and the person
        // watching gets refusals instead of an answer.
        $this->assertContains('list_todos', $names);
        $this->assertNotContains('list_shoots', $names);
        $this->assertNotContains('list_scripts', $names);
    }

    public function test_a_granted_module_brings_its_tool_with_it(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        UserPermission::create(['user_id' => $employee->id, 'module' => 'shoots', 'ability' => 'view']);

        $this->assertContains('list_shoots', $this->toolNames($this->tokenFor($employee)));
    }

    public function test_an_admin_is_shown_everything(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $names = $this->toolNames($this->tokenFor($admin));

        $this->assertContains('list_shoots', $names);
        $this->assertContains('list_scripts', $names);
    }

    public function test_calling_a_tool_you_cannot_see_is_refused(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        // Hiding it from the list is not enough; the call has to refuse too.
        $result = $this->rpc($this->tokenFor($employee), [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'list_shoots', 'arguments' => []],
        ])->assertOk();

        $result->assertJsonPath('error.code', Protocol::INVALID_PARAMS);
    }

    /** @return list<string> */
    private function toolNames(string $token): array
    {
        $tools = $this->rpc($token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertOk()
            ->json('result.tools');

        return array_column($tools, 'name');
    }

    // ——— Reading ———

    public function test_whoami_says_who_the_token_belongs_to(): void
    {
        $user = User::factory()->create(['name' => 'Aron Kumar', 'role' => User::ROLE_EMPLOYEE]);

        $data = $this->toolData($this->tokenFor($user), 'whoami');

        $this->assertSame('Aron Kumar', $data['name']);
        $this->assertFalse($data['is_admin']);
        $this->assertSame(today()->toDateString(), $data['today']);
    }

    public function test_the_timesheet_comes_back_grouped_by_day_with_its_verdict(): void
    {
        $user = User::factory()->create();
        TimesheetEntry::create([
            'user_id' => $user->id,
            'worked_on' => today()->toDateString(),
            'task' => 'Edit the SVA reel',
            'task_type' => TimesheetEntry::TASK_EDITING,
            'venture' => TimesheetVenture::ALL_CLIENTS,
            'minutes' => 120,
        ]);

        $data = $this->toolData($this->tokenFor($user), 'list_timesheet');

        $this->assertSame('2 hrs', $data['total']);
        $this->assertSame('Edit the SVA reel', $data['days'][0]['entries'][0]['task']);
        $this->assertSame('Under review', $data['days'][0]['review']);
    }

    public function test_somebody_elses_timesheet_is_refused(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $stranger = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Priya Stranger']);

        $result = $this->callTool($this->tokenFor($user), 'list_timesheet', ['person' => 'Priya Stranger']);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('whose work you can read', $result['content'][0]['text']);
    }

    public function test_a_manager_may_read_their_own_reports_timesheet(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $report = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Mine Report']);
        $report->managers()->attach($manager);

        $data = $this->toolData($this->tokenFor($manager), 'list_timesheet', ['person' => 'Mine Report']);

        $this->assertSame('Mine Report', $data['person']);
    }

    // ——— Writing ———

    public function test_an_entry_can_be_logged(): void
    {
        $user = User::factory()->create();

        $data = $this->toolData($this->tokenFor($user), 'log_timesheet_entry', [
            'task' => 'Colour grade',
            'start' => '09:00',
            'end' => '11:30',
        ]);

        $this->assertSame('2 hrs 30 mins', $data['duration']);
        $this->assertDatabaseHas('timesheet_entries', [
            'user_id' => $user->id,
            'task' => 'Colour grade',
            'minutes' => 150,
        ]);
    }

    public function test_logging_onto_a_decided_day_puts_it_back_under_review(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        // reviewed_at is NOT NULL and not fillable, so the row is built and
        // stamped before it is written, the way the controller does it.
        (new TimesheetDay([
            'user_id' => $user->id,
            'worked_on' => today()->toDateString(),
            'review_state' => TimesheetDay::APPROVED,
        ]))->forceFill(['reviewed_at' => now(), 'reviewed_by_id' => $admin->id])->save();

        $data = $this->toolData($this->tokenFor($user), 'log_timesheet_entry', [
            'task' => 'One more thing',
            'minutes' => 30,
        ]);

        // The same reopening the web form does -- a manager signed off what the
        // day said then, not what it says now.
        $this->assertStringContainsString('back under review', $data['note']);
        $this->assertSame(0, TimesheetDay::where('user_id', $user->id)->count());
    }

    public function test_an_entry_with_no_duration_is_refused(): void
    {
        $user = User::factory()->create();

        $result = $this->callTool($this->tokenFor($user), 'log_timesheet_entry', ['task' => 'Something']);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('no duration', $result['content'][0]['text']);
    }

    public function test_work_cannot_be_logged_for_a_day_that_has_not_happened(): void
    {
        $user = User::factory()->create();

        $result = $this->callTool($this->tokenFor($user), 'log_timesheet_entry', [
            'task' => 'Time travel',
            'minutes' => 60,
            'date' => today()->addWeek()->toDateString(),
        ]);

        $this->assertTrue($result['isError']);
        $this->assertSame(0, TimesheetEntry::count());
    }

    public function test_an_invented_client_is_refused_with_the_real_list(): void
    {
        $user = User::factory()->create();

        $result = $this->callTool($this->tokenFor($user), 'log_timesheet_entry', [
            'task' => 'Shoot',
            'minutes' => 60,
            'client' => 'Definitely Not A Client Ltd',
        ]);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('no client called', $result['content'][0]['text']);
    }

    public function test_a_todo_can_be_written_for_somebody_else(): void
    {
        $producer = User::factory()->create(['name' => 'Pat Producer']);
        $editor = User::factory()->create(['name' => 'Ed Editor']);

        $data = $this->toolData($this->tokenFor($producer), 'create_todo', [
            'title' => 'Cut the teaser',
            'person' => 'Ed Editor',
            'due_on' => today()->addDays(2)->toDateString(),
        ]);

        $this->assertSame('Ed Editor', $data['for']);
        $this->assertSame(3, $data['spans_days']);

        $todo = Todo::firstOrFail();
        $this->assertSame($editor->id, $todo->user_id);
        $this->assertSame($producer->id, $todo->assigned_by_id);
        // The history is the safety net; a row written without it is a hole.
        $this->assertSame(1, $todo->updates()->count());
    }

    public function test_an_ambiguous_name_asks_rather_than_guesses(): void
    {
        $caller = User::factory()->create();
        User::factory()->create(['name' => 'Sam Ali']);
        User::factory()->create(['name' => 'Sam Bhat']);

        $result = $this->callTool($this->tokenFor($caller), 'create_todo', [
            'title' => 'Something',
            'person' => 'Sam',
        ]);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('More than one person', $result['content'][0]['text']);
        $this->assertSame(0, Todo::count());
    }

    public function test_a_todo_can_be_moved_by_the_person_who_asked_for_it(): void
    {
        $producer = User::factory()->create();
        $editor = User::factory()->create();
        $todo = Todo::create([
            'user_id' => $editor->id,
            'assigned_by_id' => $producer->id,
            'title' => 'Cut the teaser',
            'starts_on' => today()->toDateString(),
            'due_on' => today()->toDateString(),
        ]);

        $data = $this->toolData($this->tokenFor($producer), 'set_todo_status', [
            'id' => $todo->id,
            'status' => Todo::STATUS_COMPLETED,
        ]);

        $this->assertSame('Completed', $data['now']);
    }

    public function test_a_stranger_cannot_move_somebody_elses_todo(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $todo = Todo::create([
            'user_id' => $owner->id,
            'assigned_by_id' => $owner->id,
            'title' => 'Not yours',
            'starts_on' => today()->toDateString(),
            'due_on' => today()->toDateString(),
        ]);

        $result = $this->callTool($this->tokenFor($stranger), 'set_todo_status', [
            'id' => $todo->id,
            'status' => Todo::STATUS_CANCELLED,
        ]);

        $this->assertTrue($result['isError']);
        $this->assertSame(Todo::STATUS_WAITING, $todo->refresh()->status);
    }

    public function test_blocking_something_requires_saying_what_by(): void
    {
        $user = User::factory()->create();
        $todo = Todo::create([
            'user_id' => $user->id,
            'assigned_by_id' => $user->id,
            'title' => 'Stuck thing',
            'starts_on' => today()->toDateString(),
            'due_on' => today()->toDateString(),
        ]);

        $result = $this->callTool($this->tokenFor($user), 'set_todo_status', [
            'id' => $todo->id,
            'status' => Todo::STATUS_BLOCKED,
        ]);

        $this->assertTrue($result['isError']);
        $this->assertSame(Todo::STATUS_WAITING, $todo->refresh()->status);
    }

    // ——— Managing tokens from the profile screen ———

    public function test_a_token_is_shown_once_and_never_again(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('mcp-tokens.store'), ['name' => 'My laptop'])
            ->assertRedirect()
            ->assertSessionHas('mcp_token_plain');

        // The next page load must not carry it -- it lives for one render.
        $this->actingAs($user)->get(route('profile.edit'))
            ->assertOk()
            ->assertSessionMissing('mcp_token_plain');
    }

    public function test_somebody_elses_token_cannot_be_revoked(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $token = McpToken::issue($stranger, 'Theirs')['token'];

        $this->actingAs($user)->delete(route('mcp-tokens.destroy', $token))->assertNotFound();

        $this->assertDatabaseHas('mcp_tokens', ['id' => $token->id]);
    }
}
