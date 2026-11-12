<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Client;
use App\Models\Script;
use App\Models\Shoot;
use App\Models\ShootCrew;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\Ai\GeminiBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Hook ideas for a script: on the script page and on set. */
class HookWriterTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Script $script;

    private User $writer;

    protected function setUp(): void
    {
        parent::setUp();
        GeminiBudget::reset();
        $this->withoutVite();
        AiSetting::current()->forceFill(['gemini_api_key' => 'AIza-test', 'gemini_monthly_cap_usd' => 5])->save();

        $this->client = Client::create(['name' => 'Janet Hospital']);
        $this->script = Script::create(['client_id' => $this->client->id, 'title' => 'Superfetation', 'status' => Script::STATUS_DRAFT, 'priority' => Script::PRIORITY_NORMAL]);
        $section = $this->script->sections()->make(['heading' => 'Script']);
        $section->position = 0;
        $section->body = '<p>Can a woman get pregnant while already pregnant? It is called superfetation.</p>';
        $section->save();

        $this->writer = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        foreach (['view', 'edit'] as $a) {
            UserPermission::create(['user_id' => $this->writer->id, 'module' => 'scripts', 'ability' => $a]);
        }

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode(['hooks' => array_map(fn ($i) => [
                'spoken' => "**Hook {$i}** — pregnant while pregnant?", 'on_screen' => "#Twins {$i}", 'first_shot' => 'Close-up of the doctor', 'angle' => 'Question',
            ], range(1, 6))])]]]]],
            'usageMetadata' => ['promptTokenCount' => 2000, 'candidatesTokenCount' => 500],
        ])]);
    }

    public function test_five_hooks_are_written_from_the_script_and_counted_against_the_limit(): void
    {
        $this->actingAs($this->writer)->postJson(route('scripts.hooks', $this->script), ['note' => 'For first-time mothers', 'language' => 'tanglish'])
            ->assertOk()
            ->assertJsonCount(5, 'hooks')
            ->assertJsonPath('hooks.0.spoken', 'Hook 1 — pregnant while pregnant?')
            ->assertJsonPath('hooks.0.on_screen', 'Twins 1')
            ->assertJsonPath('reused', false);

        Http::assertSent(fn (HttpRequest $r) => str_contains($t = $r['contents'][0]['parts'][0]['text'], 'superfetation')
            && str_contains($t, 'For first-time mothers') && str_contains($t, 'Tanglish'));
        $this->assertSame('hooks', DB::table('ai_usage')->value('feature'));

        // The same ask again: no new call.
        $this->postJson(route('scripts.hooks', $this->script), ['note' => 'For first-time mothers', 'language' => 'tanglish'])->assertOk()->assertJsonPath('reused', true);
        Http::assertSentCount(1);
    }

    public function test_a_hook_goes_to_the_top_of_the_script(): void
    {
        $this->actingAs($this->writer)->postJson(route('scripts.hooks.use', $this->script), ['spoken' => 'Pregnant while pregnant?', 'on_screen' => 'Is this possible?', 'first_shot' => 'Doctor close-up'])->assertOk();

        $sections = $this->script->sections()->get();
        $this->assertSame(['Hook', 'Script'], $sections->pluck('heading')->all());
        $this->assertStringContainsString('Pregnant while pregnant?', $sections[0]->body);
        $this->assertStringContainsString('On screen: Is this possible?', $sections[0]->body);
    }

    public function test_putting_a_hook_in_needs_edit_rights(): void
    {
        UserPermission::query()->where('ability', 'edit')->delete();

        $this->actingAs($this->writer->fresh())->postJson(route('scripts.hooks.use', $this->script), ['spoken' => 'x'])->assertForbidden();
    }

    public function test_the_crew_get_hooks_on_set_for_their_clients_scripts_only(): void
    {
        $crew = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $shoot = Shoot::create(['title' => 'Janet', 'client_id' => $this->client->id, 'starts_at' => now(), 'status' => Shoot::STATUS_CONFIRMED]);
        ShootCrew::create(['shoot_id' => $shoot->id, 'user_id' => $crew->id, 'role' => 'Camera']);
        $other = Script::create(['client_id' => Client::create(['name' => 'Other'])->id, 'title' => 'Not theirs', 'status' => Script::STATUS_DRAFT, 'priority' => Script::PRIORITY_NORMAL]);

        $this->actingAs($crew)->get(route('my.shoots.run', $shoot))->assertOk()->assertSee('open-hooks', false);
        $this->postJson(route('my.shoots.hooks', [$shoot, $this->script]))->assertOk()->assertJsonCount(5, 'hooks');
        $this->postJson(route('my.shoots.hooks.use', [$shoot, $this->script]), ['spoken' => 'On set hook'])->assertOk();
        $this->postJson(route('my.shoots.hooks', [$shoot, $other]))->assertNotFound();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))->postJson(route('my.shoots.hooks', [$shoot, $this->script]))->assertNotFound();
    }

    public function test_the_script_page_shows_the_hook_panel(): void
    {
        $this->actingAs($this->writer)->get(route('scripts.show', $this->script))->assertOk()->assertSee('Hook ideas');
    }

    public function test_past_the_monthly_limit_nothing_is_asked(): void
    {
        DB::table('ai_usage')->insert(['provider' => 'gemini', 'model' => 'm', 'cost_usd' => 5, 'created_at' => now()]);

        $this->actingAs($this->writer)->postJson(route('scripts.hooks', $this->script))->assertStatus(429)->assertJsonPath('code', 'budget');
        Http::assertNothingSent();
    }
}
