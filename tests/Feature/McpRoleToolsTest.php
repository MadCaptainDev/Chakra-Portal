<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\McpToken;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MCP by role: editors get their own board and timesheet hints, crew get
 * their shoots, admins and managers get the editing floor (a manager only
 * their team).
 */
class McpRoleToolsTest extends TestCase
{
    use RefreshDatabase;

    private function data(User $user, string $name, array $arguments = []): array
    {
        $result = $this->withHeaders(['Authorization' => 'Bearer '.McpToken::issue($user, 'Test')['plain']])
            ->postJson(route('mcp'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]])
            ->assertOk()->json('result');
        $this->assertArrayNotHasKey('isError', $result, 'Tool error: '.($result['content'][0]['text'] ?? ''));

        return json_decode($result['content'][0]['text'], true);
    }

    private function names(User $user): array
    {
        return collect($this->withHeaders(['Authorization' => 'Bearer '.McpToken::issue($user, 'Test')['plain']])
            ->postJson(route('mcp'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->json('result.tools'))->pluck('name')->all();
    }

    public function test_each_role_gets_the_tools_for_its_work(): void
    {
        $editor = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Gokul']);
        $manager = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Sanjai']);
        $editor->managers()->attach($manager->id);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $mine = ['my_editing', 'timesheet_hint', 'my_shoots'];
        foreach ($mine as $tool) {
            $this->assertContains($tool, $this->names($editor));
        }
        $this->assertNotContains('editor_updates', $this->names($editor));
        $this->assertContains('editor_updates', $this->names($manager));
        $this->assertContains('editor_updates', $this->names($admin));
    }

    public function test_editor_updates_and_timesheet_hints_read_the_editing_day(): void
    {
        $editor = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Gokul']);
        $outsider = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Outsider']);
        $manager = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Sanjai']);
        $editor->managers()->attach($manager->id);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->travelTo(today()->setTime(10, 0));
        $id = $this->actingAs($editor)->postJson(route('my.editing.store'), ['title' => 'SVA - Cotton Frock'])->json('card.id');
        $this->postJson(route('my.editing.act', $id), ['action' => 'start']);
        $this->travelTo(today()->setTime(11, 30));
        $this->postJson(route('my.editing.act', $id), ['action' => 'done']);
        $other = $this->actingAs($outsider)->postJson(route('my.editing.store'), ['title' => 'Not your team'])->json('card.id');
        $this->postJson(route('my.editing.act', $other), ['action' => 'start']);
        $this->travelTo(today()->setTime(12, 0));

        $floor = $this->data($admin, 'editor_updates');
        $this->assertSame('every editor', $floor['scope']);
        $this->assertSame('SVA - Cotton Frock', $floor['waiting_for_review'][0]['title']);
        $this->assertContains('Outsider', array_column($floor['editors'], 'name'));

        $team = $this->data($manager, 'editor_updates');
        $this->assertSame('your team', $team['scope']);
        $this->assertNotContains('Outsider', array_column($team['editors'], 'name'));

        $hint = $this->data($editor, 'timesheet_hint');
        $this->assertSame('Edit — SVA - Cotton Frock', $hint['to_add'][0]['task']);
        $this->assertSame(['10:00', '11:30', 90], [$hint['to_add'][0]['start'], $hint['to_add'][0]['end'], $hint['to_add'][0]['minutes']]);

        // On My Timesheet too, as a one-tap prefilled add.
        $this->withoutVite();
        $this->actingAs($editor)->get(route('my.timesheet'))->assertOk()
            ->assertSee('From your day')->assertSee('Edit — SVA - Cotton Frock')->assertSee('started_at=10%3A00', false);

        $this->assertSame('SVA - Cotton Frock', $this->data($editor, 'my_editing')['waiting_for_review'][0]['title']);
    }

    public function test_my_shoots_lists_the_callers_shoots_with_scripts_to_shoot(): void
    {
        $crew = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Aron']);
        $client = Client::create(['name' => 'Squad Architects']);
        $shoot = Shoot::create(['title' => 'Squad shoot', 'client_id' => $client->id, 'starts_at' => now()->addDay(), 'status' => Shoot::STATUS_PLANNED]);
        $shoot->crew()->create(['user_id' => $crew->id]);
        \App\Models\Script::create(['client_id' => $client->id, 'title' => 'Italian marble', 'status' => \App\Models\Script::STATUS_COMPLETED, 'priority' => \App\Models\Script::PRIORITY_NORMAL]);

        $data = $this->data($crew, 'my_shoots');
        $this->assertSame('Squad shoot', $data['shoots'][0]['title']);
        $this->assertSame('Italian marble', $data['shoots'][0]['scripts_to_shoot'][0]['title']);
    }
}
