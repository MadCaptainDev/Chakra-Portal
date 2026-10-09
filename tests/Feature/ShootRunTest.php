<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\ShootCrew;
use App\Models\ShootVideo;
use App\Models\User;
use App\Services\Notion\NotionShootStatus;
use App\Services\ShootRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Tests\TestCase;

class ShootRunTest extends TestCase
{
    use RefreshDatabase;

    private function shoot(array $attributes = []): Shoot
    {
        return Shoot::create(array_merge([
            'title' => 'Pet clinic reels',
            'starts_at' => now()->addHour(),
            'status' => Shoot::STATUS_CONFIRMED,
        ], $attributes));
    }

    private function crewMember(Shoot $shoot, ?User $user = null): User
    {
        $user = $user ?: User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        ShootCrew::create(['shoot_id' => $shoot->id, 'user_id' => $user->id, 'role' => 'Camera']);

        return $user;
    }

    public function test_a_crew_member_can_open_and_start_their_shoot(): void
    {
        $shoot = $this->shoot();
        $crew = $this->crewMember($shoot);

        $this->actingAs($crew)->get(route('my.shoots.run', $shoot))
            ->assertOk()
            ->assertSee('Start shoot');

        $this->actingAs($crew)->post(route('my.shoots.start', $shoot))->assertRedirect();

        $this->assertTrue($shoot->fresh()->isInProgress());
        $this->assertSame($crew->id, $shoot->fresh()->started_by_id);
    }

    public function test_somebody_not_on_the_shoot_cannot_see_it(): void
    {
        $shoot = $this->shoot();
        $stranger = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($stranger)->get(route('my.shoots.run', $shoot))->assertNotFound();
        $this->actingAs($stranger)->post(route('my.shoots.start', $shoot))->assertNotFound();
    }

    public function test_a_crew_member_without_the_shoots_module_still_gets_in(): void
    {
        // The whole point of the separate gate: four of six employees hold no
        // module permissions and they are the ones holding the camera.
        $shoot = $this->shoot();
        $crew = $this->crewMember($shoot);

        $this->assertFalse($crew->hasPermission('shoots', 'view'));
        $this->actingAs($crew)->get(route('my.shoots.run', $shoot))->assertOk();
    }

    public function test_one_person_cannot_run_two_shoots_at_once(): void
    {
        $first = $this->shoot(['title' => 'Morning shoot']);
        $second = $this->shoot(['title' => 'Afternoon shoot']);
        $crew = $this->crewMember($first);
        $this->crewMember($second, $crew);

        ShootRun::start($first, $crew);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Morning shoot');

        ShootRun::start($second, $crew);
    }

    public function test_separate_crews_may_shoot_in_parallel(): void
    {
        $first = $this->shoot(['title' => 'Unit A']);
        $second = $this->shoot(['title' => 'Unit B']);

        ShootRun::start($first, $this->crewMember($first));
        ShootRun::start($second, $this->crewMember($second));

        $this->assertTrue($first->fresh()->isInProgress());
        $this->assertTrue($second->fresh()->isInProgress());
    }

    public function test_the_blocked_shoot_names_the_one_in_the_way(): void
    {
        $first = $this->shoot(['title' => 'Morning shoot']);
        $second = $this->shoot(['title' => 'Afternoon shoot']);
        $crew = $this->crewMember($first);
        $this->crewMember($second, $crew);

        ShootRun::start($first, $crew);

        $this->actingAs($crew)->get(route('my.shoots.run', $second))
            ->assertOk()
            ->assertSee('Morning shoot')
            ->assertDontSee('Start shoot');
    }

    public function test_videos_are_filed_in_order_with_a_photo_and_notes(): void
    {
        $shoot = $this->shoot();
        $crew = $this->crewMember($shoot);
        ShootRun::start($shoot, $crew);

        $this->actingAs($crew)->post(route('my.shoots.videos.store', $shoot), [
            'name' => 'Clinic exterior',
            'notes' => 'Golden hour, shot wide',
            'photo' => UploadedFile::fake()->image('still.jpg'),
        ])->assertRedirect();

        $this->actingAs($crew)->post(route('my.shoots.videos.store', $shoot), [
            'name' => 'Vet interview',
            'photo' => UploadedFile::fake()->image('interview.jpg'),
        ])->assertRedirect();

        $videos = $shoot->fresh()->videos;

        $this->assertCount(2, $videos);
        $this->assertSame([1, 2], $videos->pluck('position')->all());
        $this->assertSame('Clinic exterior', $videos->first()->name);
        $this->assertSame('Golden hour, shot wide', $videos->first()->notes);
        $this->assertNotNull($videos->first()->photo_path);
        $this->assertFileExists(public_path($videos->first()->photo_path));
        $this->assertNotNull($videos->last()->photo_path);

        // Tidy up: PublicUpload writes into public/, not a faked disk.
        $videos->each(fn ($v) => @unlink(public_path($v->photo_path)));
    }

    public function test_a_video_needs_a_name(): void
    {
        $shoot = $this->shoot();
        $crew = $this->crewMember($shoot);
        ShootRun::start($shoot, $crew);

        $this->actingAs($crew)->post(route('my.shoots.videos.store', $shoot), ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->assertCount(0, $shoot->fresh()->videos);
    }

    public function test_a_video_needs_a_photo(): void
    {
        $shoot = $this->shoot();
        $crew = $this->crewMember($shoot);
        ShootRun::start($shoot, $crew);

        $this->actingAs($crew)->post(route('my.shoots.videos.store', $shoot), ['name' => 'No still'])
            ->assertSessionHasErrors('photo');

        $this->assertCount(0, $shoot->fresh()->videos);
    }

    // ——— Reached ———

    public function test_any_crew_member_can_mark_the_crew_reached_and_the_first_one_counts(): void
    {
        $shoot = $this->shoot();
        $director = $this->crewMember($shoot);
        $camera = $this->crewMember($shoot);

        $this->actingAs($director)->post(route('my.shoots.reached', $shoot))->assertRedirect();
        $first = $shoot->fresh()->reached_at;

        $this->travel(10)->minutes();
        $this->actingAs($camera)->post(route('my.shoots.reached', $shoot))->assertRedirect();

        $shoot->refresh();
        $this->assertTrue($shoot->hasReached());
        $this->assertEquals($first, $shoot->reached_at);
        $this->assertSame($director->id, $shoot->reached_by_id);
        $this->assertFalse($shoot->isInProgress(), 'reaching is not rolling');
    }

    public function test_someone_off_the_shoot_cannot_mark_it_reached(): void
    {
        $shoot = $this->shoot();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))
            ->post(route('my.shoots.reached', $shoot))->assertNotFound();

        $this->assertFalse($shoot->fresh()->hasReached());
    }

    public function test_starting_without_tapping_reached_still_records_arrival(): void
    {
        $shoot = $this->shoot();
        $crew = $this->crewMember($shoot);

        ShootRun::start($shoot, $crew);

        $shoot->refresh();
        $this->assertNotNull($shoot->reached_at);
        $this->assertSame($crew->id, $shoot->reached_by_id);
    }

    public function test_starting_after_reached_keeps_the_arrival_time(): void
    {
        $shoot = $this->shoot();
        $crew = $this->crewMember($shoot);
        ShootRun::reached($shoot, $crew);
        $arrived = $shoot->fresh()->reached_at;

        $this->travel(45)->minutes();
        ShootRun::start($shoot->fresh(), $crew);

        $this->assertEquals($arrived, $shoot->fresh()->reached_at);
    }

    // ——— My Shoots ———

    public function test_my_shoots_lists_only_my_shoots_grouped_live_today_and_upcoming(): void
    {
        $live = $this->shoot(['title' => 'Live one', 'starts_at' => now()->subHour()]);
        $crew = $this->crewMember($live);
        ShootRun::start($live, $crew);

        $this->crewMember($this->shoot(['title' => 'Later today', 'starts_at' => now()->endOfDay()->subMinute()]), $crew);
        $this->crewMember($this->shoot(['title' => 'Next week', 'starts_at' => now()->addWeek()]), $crew);
        $this->crewMember($this->shoot(['title' => 'Next year', 'starts_at' => now()->addYear()]), $crew);
        $this->crewMember($this->shoot(['title' => 'Not mine', 'starts_at' => now()->addHour()]));

        $this->actingAs($crew)->get(route('my.shoots'))
            ->assertOk()
            ->assertSeeInOrder(['Live now', 'Live one', 'Today', 'Later today', 'Coming up', 'Next week'])
            ->assertSee(route('my.shoots.run', $live), false)
            ->assertDontSee('Next year')
            ->assertDontSee('Not mine');
    }

    public function test_videos_cannot_be_filed_before_the_shoot_starts(): void
    {
        $shoot = $this->shoot();
        $crew = $this->crewMember($shoot);

        $this->actingAs($crew)->post(route('my.shoots.videos.store', $shoot), ['name' => 'Too early', 'photo' => UploadedFile::fake()->image('x.jpg')])
            ->assertSessionHasErrors('name');

        $this->assertCount(0, $shoot->fresh()->videos);
    }

    public function test_finishing_wraps_the_shoot_and_completes_the_booking(): void
    {
        $shoot = $this->shoot();
        $crew = $this->crewMember($shoot);
        ShootRun::start($shoot, $crew);

        $this->actingAs($crew)->post(route('my.shoots.finish', $shoot))->assertRedirect();

        $shoot = $shoot->fresh();

        $this->assertTrue($shoot->hasWrapped());
        $this->assertFalse($shoot->isInProgress());
        $this->assertSame(Shoot::STATUS_COMPLETED, $shoot->status);
    }

    public function test_a_wrapped_shoot_takes_no_more_videos_and_cannot_restart(): void
    {
        $shoot = $this->shoot();
        $crew = $this->crewMember($shoot);
        ShootRun::start($shoot, $crew);
        ShootRun::finish($shoot, $crew);

        $this->actingAs($crew)->post(route('my.shoots.videos.store', $shoot), ['name' => 'After the fact', 'photo' => UploadedFile::fake()->image('x.jpg')])
            ->assertSessionHasErrors(['name' => 'This shoot has already been wrapped.']);

        $this->expectException(RuntimeException::class);
        ShootRun::start($shoot->fresh(), $crew);
    }

    public function test_wrapping_frees_the_person_for_the_next_shoot(): void
    {
        $first = $this->shoot(['title' => 'Morning shoot']);
        $second = $this->shoot(['title' => 'Afternoon shoot']);
        $crew = $this->crewMember($first);
        $this->crewMember($second, $crew);

        ShootRun::start($first, $crew);
        ShootRun::finish($first, $crew);
        ShootRun::start($second, $crew);

        $this->assertTrue($second->fresh()->isInProgress());
    }

    public function test_starting_twice_is_not_an_error_and_does_not_move_the_clock(): void
    {
        $shoot = $this->shoot();
        $crew = $this->crewMember($shoot);

        ShootRun::start($shoot, $crew);
        $startedAt = $shoot->fresh()->started_at;

        ShootRun::start($shoot->fresh(), $crew);

        $this->assertEquals($startedAt, $shoot->fresh()->started_at);
    }

    public function test_a_portal_only_shoot_skips_notion_without_failing(): void
    {
        // No notion_shoot_id: there is no card to move, and that must not
        // stop the crew working.
        $shoot = $this->shoot();
        $crew = $this->crewMember($shoot);

        $this->assertFalse(NotionShootStatus::push($shoot, NotionShootStatus::SHOOTING));

        ShootRun::start($shoot, $crew);

        $this->assertTrue($shoot->fresh()->isInProgress());
    }

    public function test_every_state_of_the_runner_renders(): void
    {
        $shoot = $this->shoot();
        $crew = $this->crewMember($shoot);

        $this->actingAs($crew)->get(route('my.shoots.run', $shoot))
            ->assertOk()->assertSee('reached the location', false)->assertSee('Start shoot now');

        ShootRun::reached($shoot, $crew);
        $this->actingAs($crew)->get(route('my.shoots.run', $shoot))
            ->assertOk()->assertSee('Reached at')->assertSee('Start shoot');

        ShootRun::start($shoot, $crew);
        $this->actingAs($crew)->get(route('my.shoots.run', $shoot))
            ->assertOk()->assertSee('Rolling')->assertSee('Save video');

        ShootRun::recordVideo($shoot, $crew, 'Clinic exterior');
        $this->actingAs($crew)->get(route('my.shoots.run', $shoot))
            ->assertOk()->assertSee('Clinic exterior');

        ShootRun::finish($shoot, $crew);
        $this->actingAs($crew)->get(route('my.shoots.run', $shoot))
            ->assertOk()->assertSee('wrap');
    }

    public function test_deleting_a_shoot_takes_its_videos_with_it(): void
    {
        $shoot = $this->shoot();
        $crew = $this->crewMember($shoot);
        ShootRun::start($shoot, $crew);
        ShootRun::recordVideo($shoot, $crew, 'Only one');

        $shoot->delete();

        $this->assertSame(0, ShootVideo::query()->count());
    }
}
