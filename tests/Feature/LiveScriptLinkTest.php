<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Script;
use App\Models\ScriptSection;
use App\Models\Shoot;
use App\Models\ShootCrew;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveScriptLinkTest extends TestCase
{
    use RefreshDatabase;

    private function script(array $overrides = []): Script
    {
        $script = Script::create($overrides + [
            'title' => 'Clinic tour reel',
            'status' => Script::STATUS_READY,
            'priority' => Script::PRIORITY_NORMAL,
        ]);
        $section = $script->sections()->make(['heading' => 'Hook', 'body' => '<p>Original line</p>']);
        $section->position = 0;
        $section->save();

        return $script->refresh();
    }

    public function test_a_writer_turns_the_link_on_and_off(): void
    {
        $writer = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $writer->syncPermissions(['scripts' => ['view', 'edit']]);
        $script = $this->script();

        $this->actingAs($writer->refresh())->post(route('scripts.live-link', $script))->assertRedirect();
        $token = $script->fresh()->share_token;
        $this->assertNotNull($token);

        // Turning it on again keeps the same link.
        $this->actingAs($writer)->post(route('scripts.live-link', $script));
        $this->assertSame($token, $script->fresh()->share_token);

        $this->actingAs($writer)->delete(route('scripts.live-link.revoke', $script))->assertRedirect();
        $this->assertNull($script->fresh()->share_token);
        $this->get(route('scripts.live', $token))->assertNotFound();
    }

    public function test_anyone_with_the_link_reads_and_edits_with_no_login(): void
    {
        $script = $this->script();
        $url = $script->shareLink();
        $token = $script->share_token;
        $section = $script->sections->first();

        $this->get($url)->assertOk()->assertSee('Clinic tour reel')->assertSee('Original line', false);

        $this->patchJson(route('scripts.live.update', [$token, $section->id]), [
            'version' => $section->version, 'heading' => 'Hook', 'body' => '<p>New line</p><script>x</script>', 'name' => 'Priya',
        ])->assertOk()->assertJson(['version' => $section->version + 1]);

        $this->assertStringContainsString('New line', $section->fresh()->body);
        $this->assertStringNotContainsString('<script', $section->fresh()->body);
        $this->assertSame('Priya', $script->fresh()->lastEditorName());

        $this->getJson(route('scripts.live.state', $token))
            ->assertOk()->assertJsonPath('edited_by', 'Priya')->assertJsonPath('sections.0.version', $section->version + 1);

        // A stale version is refused with the newer copy, not saved over.
        $this->patchJson(route('scripts.live.update', [$token, $section->id]), [
            'version' => $section->version, 'heading' => 'Hook', 'body' => '<p>Old</p>',
        ])->assertStatus(409)->assertJsonPath('current.version', $section->version + 1);

        $this->postJson(route('scripts.live.store', $token), ['heading' => 'CTA'])->assertCreated();
        $this->assertSame(['Hook', 'CTA'], $script->sections()->pluck('heading')->all());
    }

    public function test_a_wrong_token_or_another_scripts_section_is_a_404(): void
    {
        $script = $this->script();
        $script->shareLink();
        $other = $this->script(['title' => 'Other']);
        $foreign = $other->sections->first();

        $this->get(route('scripts.live', 'nope'))->assertNotFound();
        $this->patchJson(route('scripts.live.update', [$script->share_token, $foreign->id]), [
            'version' => 1, 'heading' => 'Hook', 'body' => 'hijack',
        ])->assertNotFound();
        $this->assertStringNotContainsString('hijack', (string) ScriptSection::find($foreign->id)->body);
    }

    public function test_the_shoot_crew_can_share_their_clients_script_only(): void
    {
        $client = Client::factory()->create();
        $shoot = Shoot::create(['title' => 'Clinic shoot', 'starts_at' => now(), 'status' => Shoot::STATUS_CONFIRMED, 'client_id' => $client->id]);
        $crew = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        ShootCrew::create(['shoot_id' => $shoot->id, 'user_id' => $crew->id, 'role' => 'Camera']);
        $ours = $this->script(['client_id' => $client->id]);
        $theirs = $this->script(['client_id' => Client::factory()->create()->id]);

        $url = $this->actingAs($crew)->postJson(route('my.shoots.script-link', [$shoot, $ours]))->assertOk()->json('url');
        $this->assertSame(route('scripts.live', $ours->fresh()->share_token), $url);

        $this->actingAs($crew)->postJson(route('my.shoots.script-link', [$shoot, $theirs]))->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))
            ->postJson(route('my.shoots.script-link', [$shoot, $ours]))->assertNotFound();
    }
}
