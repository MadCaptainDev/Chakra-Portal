<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\Client;
use App\Models\Script;
use App\Models\Shoot;
use App\Models\ShootCrew;
use App\Models\ShootEvent;
use App\Models\User;
use App\Services\ShootRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Card offload for Chakra Planner (PlannerOffloadController) and the
 * runner's "Start take" button that feeds it.
 */
class PlannerOffloadApiTest extends TestCase
{
    use RefreshDatabase;

    private User $crew;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 11, 20)->setTime(16, 0));
        $this->crew = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'email' => 'crew@chakra.test', 'password' => bcrypt('secret-pass')]);
    }

    private function token(?User $user = null): array
    {
        $token = $this->postJson(route('api.planner.login'), ['email' => ($user ?? $this->crew)->email, 'password' => 'secret-pass', 'device_name' => 'Test Mac'])
            ->assertCreated()->json('token');

        return ['Authorization' => 'Bearer '.$token];
    }

    private function runShoot(Client $client, string $title): Shoot
    {
        $shoot = Shoot::create(['title' => $title, 'client_id' => $client->id, 'starts_at' => now(), 'status' => Shoot::STATUS_CONFIRMED]);
        ShootCrew::create(['shoot_id' => $shoot->id, 'user_id' => $this->crew->id, 'role' => 'Camera']);
        ShootRun::start($shoot, $this->crew);

        return $shoot->fresh();
    }

    public function test_shoots_list_merges_videos_events_and_scripts_into_takes(): void
    {
        $client = Client::factory()->create(['name' => 'Squad Architects', 'folder_prefix' => 'Squad', 'drive_root' => '/Volumes/Chakra/Squad', 'pending_path_template' => '{MONTH_SHORT}- {year}/PENDING']);
        $shoot = $this->runShoot($client, 'Squad reels');

        $this->travel(10)->minutes();
        $first = ShootRun::recordVideo($shoot, $this->crew, 'Office tour');

        // A video row that was deleted afterwards: only its event remains.
        $this->travel(10)->minutes();
        $gone = ShootRun::recordVideo($shoot, $this->crew, 'Lobby');
        $gone->delete();

        // Marked shot from the script, with no video filed for it.
        $this->travel(10)->minutes();
        $script = Script::create(['client_id' => $client->id, 'title' => 'Hook 3', 'status' => Script::STATUS_DRAFT, 'priority' => Script::PRIORITY_NORMAL]);
        $script->markRecorded(now());
        // Date-only shot_at says nothing about the time: left out.
        Script::create(['client_id' => $client->id, 'title' => 'Day only', 'status' => Script::STATUS_DRAFT, 'priority' => Script::PRIORITY_NORMAL])->markRecorded(today());

        $this->travel(5)->minutes();
        ShootRun::finish($shoot, $this->crew);
        Camera::create(['serial' => '5012345', 'name' => 'FX3 A', 'clock_offset_seconds' => -19200]);

        $res = $this->withHeaders($this->token())->getJson(route('api.planner.offload.shoots', ['from' => '2026-11-20', 'to' => '2026-11-20', 'status' => 'completed']))
            ->assertOk();

        $res->assertJsonPath('data.0.id', $shoot->id)
            ->assertJsonPath('data.0.client.folder_prefix', 'Squad')
            ->assertJsonPath('data.0.client.drive_root', '/Volumes/Chakra/Squad')
            ->assertJsonPath('data.0.client.pending_path', 'NOV- 2026/PENDING')
            ->assertJsonPath('cameras.0.serial', '5012345')
            ->assertJsonPath('cameras.0.clock_offset_seconds', -19200);
        $this->assertNotNull($res->json('data.0.started_at'));
        $this->assertNotNull($res->json('data.0.wrapped_at'));

        $takes = collect($res->json('data.0.takes'));
        $this->assertSame(['Office tour', 'Lobby', 'Hook 3'], $takes->pluck('name')->all());
        $this->assertSame(['shoot_video', 'shoot_event', 'script'], $takes->pluck('source')->all());
        $this->assertSame('sv:'.$first->id, $takes[0]['id']);
        $this->assertSame($first->id, $takes[0]['shoot_video_id']);
        $this->assertNull($takes[1]['shoot_video_id']);
        $this->assertSame('script:'.$script->id, $takes[2]['id']);
    }

    public function test_cancelled_shoots_are_left_out_and_defaults_fill_in(): void
    {
        $client = Client::factory()->create(['name' => 'TPC']);
        $this->runShoot($client, 'TPC shoot');
        Shoot::create(['title' => 'Called off', 'client_id' => $client->id, 'starts_at' => now(), 'status' => Shoot::STATUS_CANCELLED]);

        $res = $this->withHeaders($this->token())->getJson(route('api.planner.offload.shoots', ['from' => '2026-11-20', 'to' => '2026-11-20']))->assertOk();

        $this->assertCount(1, $res->json('data'));
        $res->assertJsonPath('data.0.client.folder_prefix', 'TPC')
            ->assertJsonPath('data.0.client.pending_path_template', '{month_short} - {year}/pending')
            ->assertJsonPath('data.0.client.pending_path', 'Nov - 2026/pending')
            ->assertJsonPath('data.0.client.drive_root', null)
            ->assertJsonPath('data.0.wrapped_at', null);
    }

    public function test_footage_copied_stamps_the_video_and_logs_an_event_once(): void
    {
        $shoot = $this->runShoot(Client::factory()->create(), 'Reels');
        $video = ShootRun::recordVideo($shoot, $this->crew, 'Office tour');
        ShootRun::finish($shoot, $this->crew);

        $body = ['clip_count' => 14, 'bytes' => 53_687_091_200, 'folder' => '/Volumes/Chakra/Squad/Nov - 2026/pending/Squad - Office tour',
            'checksum' => ['algo' => 'xxh3-64', 'digest' => 'ab12', 'files' => 14], 'camera_serials' => ['5012345']];
        $headers = $this->token();

        $this->withHeaders($headers)->postJson(route('api.planner.offload.footage-copied', $video->id), $body)
            ->assertOk()
            ->assertJsonPath('already_copied', false)
            ->assertJsonPath('shoot_all_copied', true);
        $this->assertNotNull($video->fresh()->footage_copied_at);

        $event = ShootEvent::query()->where('type', ShootEvent::FOOTAGE_COPIED)->sole();
        $this->assertSame(14, $event->meta['clips']);
        $this->assertSame('xxh3-64', $event->meta['checksum']['algo']);

        $this->withHeaders($headers)->postJson(route('api.planner.offload.footage-copied', $video->id), $body)
            ->assertOk()->assertJsonPath('already_copied', true);

        $this->withHeaders($headers)->postJson(route('api.planner.offload.footage-copied', 999999), $body)->assertNotFound();
        $this->withHeaders($headers)->postJson(route('api.planner.offload.footage-copied', $video->id), ['clip_count' => 1])->assertUnprocessable();
    }

    public function test_cameras_save_a_learned_clock_offset(): void
    {
        $headers = $this->token();
        $this->withHeaders($headers)->postJson(route('api.planner.offload.camera', '5012345'), ['clock_offset_seconds' => -19200, 'name' => 'FX3 B'])
            ->assertCreated()->assertJsonPath('data.clock_offset_seconds', -19200);
        $this->withHeaders($headers)->postJson(route('api.planner.offload.camera', '5012345'), ['clock_offset_seconds' => -19100])
            ->assertOk()->assertJsonPath('data.name', 'FX3 B');

        $this->assertSame(-19100, Camera::query()->where('serial', '5012345')->value('clock_offset_seconds'));
    }

    public function test_crew_without_planner_permission_can_offload_but_clients_cannot(): void
    {
        $this->assertFalse($this->crew->can('planner.view'));
        $this->withHeaders($this->token())->getJson(route('api.planner.offload.shoots'))->assertOk();
        $this->getJson(route('api.planner.offload.shoots'), ['Authorization' => 'Bearer nope'])->assertUnauthorized();
    }

    public function test_start_take_is_stamped_on_the_next_video(): void
    {
        $shoot = $this->runShoot(Client::factory()->create(), 'Reels');

        $this->actingAs($this->crew)->post(route('my.shoots.take', $shoot))->assertRedirect();
        $startedAt = $shoot->fresh()->take_started_at;
        $this->assertNotNull($startedAt);

        $this->actingAs($this->crew)->get(route('my.shoots.run', $shoot))->assertOk()->assertSee('Take rolling');

        $this->travel(3)->minutes();
        $video = ShootRun::recordVideo($shoot->fresh(), $this->crew, 'Office tour');

        $this->assertEquals($startedAt, $video->fresh()->started_at);
        $this->assertNull($shoot->fresh()->take_started_at);

        // The next video without a take pressed has no start.
        $this->assertNull(ShootRun::recordVideo($shoot->fresh(), $this->crew, 'Lobby')->started_at);

        $res = $this->withHeaders($this->token())->getJson(route('api.planner.offload.shoots'))->assertOk();
        $this->assertSame($startedAt->toIso8601String(), $res->json('data.0.takes.0.started_at'));
    }

    public function test_start_take_needs_a_running_shoot(): void
    {
        $shoot = Shoot::create(['title' => 'Later', 'starts_at' => now()->addDay(), 'status' => Shoot::STATUS_CONFIRMED]);
        ShootCrew::create(['shoot_id' => $shoot->id, 'user_id' => $this->crew->id, 'role' => 'Camera']);

        $this->actingAs($this->crew)->post(route('my.shoots.take', $shoot))->assertSessionHasErrors('take');
    }

    public function test_client_form_rejects_unknown_placeholders(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = Client::factory()->create(['name' => 'SVA Silks']);

        $this->actingAs($admin)->put(route('clients.update', $client), ['name' => 'SVA Silks', 'pending_path_template' => '{monthh}/pending'])
            ->assertSessionHasErrors('pending_path_template');

        $this->actingAs($admin)->put(route('clients.update', $client), ['name' => 'SVA Silks', 'folder_prefix' => 'SVA Tier 2', 'pending_path_template' => '{month_short} - {year}/Pending'])
            ->assertSessionHasNoErrors();
        $this->assertSame('SVA Tier 2', $client->fresh()->folder_prefix);
    }
}
