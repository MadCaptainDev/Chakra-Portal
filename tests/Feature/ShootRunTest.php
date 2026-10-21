<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Script;
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
            ->assertSee('on the way');

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

    // ——— On the way ———

    public function test_the_crew_can_say_they_are_on_the_way_and_the_first_tap_counts(): void
    {
        $shoot = $this->shoot();
        $director = $this->crewMember($shoot);
        $camera = $this->crewMember($shoot);

        $this->actingAs($director)->post(route('my.shoots.depart', $shoot))->assertRedirect();
        $first = $shoot->fresh()->departed_at;
        $this->travel(5)->minutes();
        $this->actingAs($camera)->post(route('my.shoots.depart', $shoot));

        $shoot->refresh();
        $this->assertEquals($first, $shoot->departed_at);
        $this->assertSame($director->id, $shoot->departed_by_id);
        $this->assertFalse($shoot->hasReached());

        $this->actingAs($director)->get(route('my.shoots.run', $shoot))
            ->assertOk()->assertSee('On the way since')->assertSee('reached the location', false);
    }

    public function test_the_runner_offers_on_the_way_first_and_reached_as_the_shortcut(): void
    {
        $shoot = $this->shoot();
        $crew = $this->crewMember($shoot);

        $this->actingAs($crew)->get(route('my.shoots.run', $shoot))
            ->assertOk()->assertSee('on the way')->assertSee('Already there?');
    }

    public function test_someone_off_the_shoot_cannot_say_it_is_on_the_way(): void
    {
        $shoot = $this->shoot();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))
            ->post(route('my.shoots.depart', $shoot))->assertNotFound();
        $this->assertFalse($shoot->fresh()->hasDeparted());
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

    // ——— Removing crew (was a 500: scoped binding looked for Shoot::crews()) ———

    public function test_an_admin_can_remove_someone_from_the_crew(): void
    {
        $shoot = $this->shoot();
        $this->crewMember($shoot);
        $row = $shoot->crew()->sole();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->delete(route('shoots.crew.destroy', [$shoot, $row]))
            ->assertRedirect(route('shoots.show', $shoot));

        $this->assertSame(0, $shoot->crew()->count());
    }

    public function test_one_shoots_url_cannot_remove_crew_from_another_shoot(): void
    {
        $shoot = $this->shoot();
        $other = $this->shoot(['title' => 'Other shoot']);
        $this->crewMember($other);
        $row = $other->crew()->sole();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->delete(route('shoots.crew.destroy', [$shoot, $row]))
            ->assertNotFound();

        $this->assertSame(1, $other->crew()->count());
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
            ->assertSeeInOrder(['Rolling now', 'Live one', 'Continue shooting', 'Scripts to shoot', 'Also coming up', 'Later today', 'Next week'])
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
            ->assertOk()->assertSee('on the way')->assertSee('Already there?');

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

    // ——— Scripts on set ———

    private function script(Client $client, string $title, string $status = Script::STATUS_DRAFT, string $text = 'Hook line'): Script
    {
        $script = Script::create(['client_id' => $client->id, 'title' => $title, 'status' => $status, 'priority' => Script::PRIORITY_NORMAL]);
        $section = $script->sections()->make(['heading' => 'Script']);
        $section->position = 0;
        $section->body = '<p>'.$text.'</p>';
        $section->save();

        return $script;
    }

    public function test_my_shoots_opens_on_the_next_shoot_with_its_scripts_to_read(): void
    {
        $client = Client::create(['name' => 'Squad Architects']);
        $shoot = $this->shoot(['client_id' => $client->id, 'starts_at' => now()->addDay(), 'location' => 'Trichy office']);
        $crew = $this->crewMember($shoot);
        $this->script($client, 'Italian marble', Script::STATUS_COMPLETED, 'ZERO dhaan hook');
        $this->script($client, 'Tile showroom', Script::STATUS_READY);

        $this->actingAs($crew)->get(route('my.shoots'))
            ->assertOk()
            ->assertSeeInOrder(['Next shoot · Tomorrow', 'Trichy office', 'Open shoot', 'Scripts to shoot', '2 ready', 'Italian marble', 'Approved', 'ZERO dhaan hook']);
    }
    public function test_the_rolling_screen_carries_the_clients_scripts_unshot_and_approved_first(): void
    {
        $client = Client::create(['name' => 'Squad Architects']);
        $other = Client::create(['name' => 'Someone Else']);
        $shoot = $this->shoot(['client_id' => $client->id]);
        $crew = $this->crewMember($shoot);
        ShootRun::start($shoot, $crew);

        $draft = $this->script($client, 'Draft one');
        $approved = $this->script($client, 'Approved one', Script::STATUS_COMPLETED, 'Italian Marble? ZERO dhaan');
        $this->script($client, 'Archived one', Script::STATUS_ARCHIVED);
        $this->script($other, 'Not their script');
        Script::create(['client_id' => $client->id, 'title' => 'Empty one', 'status' => Script::STATUS_DRAFT, 'priority' => Script::PRIORITY_NORMAL]);

        $scripts = $this->actingAs($crew)->get(route('my.shoots.run', $shoot))
            ->assertOk()
            ->assertSee('ZERO dhaan')
            ->viewData('scripts');

        $this->assertSame([$approved->id, $draft->id], array_column($scripts, 'id'));
        $this->assertSame('Approved', $scripts[0]['status']);
    }

    public function test_a_video_shot_from_a_script_is_linked_and_ticked_off(): void
    {
        $client = Client::create(['name' => 'Squad Architects']);
        $shoot = $this->shoot(['client_id' => $client->id]);
        $crew = $this->crewMember($shoot);
        ShootRun::start($shoot, $crew);
        $tiles = $this->script($client, 'Which Tile for Which Room');
        $plants = $this->script($client, 'Plants');

        $this->actingAs($crew)->post(route('my.shoots.videos.store', $shoot), [
            'name' => 'Which Tile for Which Room',
            'script_id' => $tiles->id,
            'photo' => UploadedFile::fake()->image('still.jpg'),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $video = ShootVideo::firstOrFail();
        $this->assertSame($tiles->id, $video->script_id);

        $scripts = $this->actingAs($crew)->get(route('my.shoots.run', $shoot))->viewData('scripts');
        $this->assertSame([$plants->id, $tiles->id], array_column($scripts, 'id'));
        $this->assertSame('here', $scripts[1]['shot']);

        @unlink(public_path($video->photo_path));
    }

    public function test_another_clients_script_cannot_be_linked(): void
    {
        $client = Client::create(['name' => 'Squad Architects']);
        $other = Client::create(['name' => 'Someone Else']);
        $shoot = $this->shoot(['client_id' => $client->id]);
        $crew = $this->crewMember($shoot);
        ShootRun::start($shoot, $crew);
        $theirs = $this->script($other, 'Not theirs');

        $this->actingAs($crew)->post(route('my.shoots.videos.store', $shoot), [
            'name' => 'Something',
            'script_id' => $theirs->id,
            'photo' => UploadedFile::fake()->image('still.jpg'),
        ])->assertSessionHasErrors('script_id');

        $this->assertSame(0, ShootVideo::count());
    }

    public function test_a_script_marked_shot_without_a_video_goes_to_already_shot(): void
    {
        $client = Client::create(['name' => 'Squad Architects']);
        $shoot = $this->shoot(['client_id' => $client->id]);
        $crew = $this->crewMember($shoot);
        ShootRun::start($shoot, $crew);
        $old = $this->script($client, 'Shot last month', Script::STATUS_COMPLETED);
        $old->forceFill(['shot_at' => now()->subMonth()])->save();
        $new = $this->script($client, 'Not shot yet');

        $scripts = $this->actingAs($crew)->get(route('my.shoots.run', $shoot))->viewData('scripts');

        $this->assertSame([$new->id, $old->id], array_column($scripts, 'id'));
        $this->assertNull($scripts[0]['shot']);
        $this->assertSame('before', $scripts[1]['shot']);
    }

    public function test_section_times_are_filed_with_the_video_and_cleaned(): void
    {
        $client = Client::create(['name' => 'Squad Architects']);
        $shoot = $this->shoot(['client_id' => $client->id]);
        $crew = $this->crewMember($shoot);
        ShootRun::start($shoot, $crew);
        $tiles = $this->script($client, 'Tiles');

        $this->actingAs($crew)->post(route('my.shoots.videos.store', $shoot), [
            'name' => 'Tiles',
            'script_id' => $tiles->id,
            'section_log' => json_encode([
                ['heading' => 'Hook', 'shot' => true, 'takes' => 3, 'seconds' => 95, 'extra' => 'dropped'],
                ['heading' => 'CTA', 'shot' => false, 'takes' => 0, 'seconds' => 0],
                ['heading' => 'Body', 'shot' => true, 'takes' => 1, 'seconds' => 999999],
            ]),
            'photo' => UploadedFile::fake()->image('still.jpg'),
        ])->assertSessionHasNoErrors();

        $video = ShootVideo::firstOrFail();
        $this->assertSame(['heading' => 'Hook', 'shot' => true, 'takes' => 3, 'seconds' => 95], $video->section_log[0]);
        $this->assertSame(86400, $video->section_log[2]['seconds']);
        $this->assertSame(95 + 86400, $video->seconds);
        $this->assertSame('2 of 3 sections · 1441m 35s', $video->sectionSummary());

        @unlink(public_path($video->photo_path));
    }

    public function test_section_times_without_a_script_are_ignored(): void
    {
        $shoot = $this->shoot();
        $crew = $this->crewMember($shoot);
        ShootRun::start($shoot, $crew);

        $this->actingAs($crew)->post(route('my.shoots.videos.store', $shoot), [
            'name' => 'Free shot',
            'section_log' => json_encode([['heading' => 'Hook', 'shot' => true, 'takes' => 1, 'seconds' => 10]]),
            'photo' => UploadedFile::fake()->image('still.jpg'),
        ])->assertSessionHasNoErrors();

        $video = ShootVideo::firstOrFail();
        $this->assertNull($video->section_log);
        $this->assertNull($video->seconds);
        @unlink(public_path($video->photo_path));
    }

    public function test_a_single_block_script_is_cut_into_parts_and_a_written_one_keeps_its_sections(): void
    {
        $client = Client::create(['name' => 'Squad Architects']);
        $shoot = $this->shoot(['client_id' => $client->id]);
        $crew = $this->crewMember($shoot);
        ShootRun::start($shoot, $crew);
        $this->script($client, 'Block', Script::STATUS_COMPLETED,
            'Hook line that runs on for more than ten words in all of it</p><p>First uh, Sage Green.</p><p>Sage green is the trend this year in kitchens everywhere you look today</p><p>1.Bro, Italian Marble?<br>ZERO dhaan');
        $written = Script::create(['client_id' => $client->id, 'title' => 'Written', 'status' => Script::STATUS_DRAFT, 'priority' => Script::PRIORITY_NORMAL]);
        foreach (['Hook', 'CTA'] as $i => $heading) {
            $sec = $written->sections()->make(['heading' => $heading]);
            $sec->position = $i;
            $sec->body = '<p>'.$heading.' text</p><p>more</p>';
            $sec->save();
        }

        $scripts = $this->actingAs($crew)->get(route('my.shoots.run', $shoot))->viewData('scripts');

        $block = $scripts[0]['sections'];
        $this->assertSame(['Part 1 of 3', 'Part 2 of 3', 'Part 3 of 3'], array_column($block, 'heading'));
        // The one-line lead-in stays with the paragraph it introduces.
        $this->assertStringContainsString('First uh', $block[1]['body']);
        $this->assertStringContainsString('Sage green is', $block[1]['body']);
        $this->assertSame(['Hook', 'CTA'], array_column($scripts[1]['sections'], 'heading'));
    }

    public function test_the_live_feed_answers_unchanged_cheaply_and_carries_a_script_edit(): void
    {
        $client = Client::create(['name' => 'Janet']);
        $shoot = $this->shoot(['client_id' => $client->id]);
        $crew = $this->crewMember($shoot);
        ShootRun::start($shoot, $crew);
        $script = $this->script($client, 'Ranking');

        $first = $this->actingAs($crew)->getJson(route('my.shoots.live', $shoot))->assertOk()->json();
        $this->assertTrue($first['changed']);
        $rev = $first['scripts'][0]['rev'];

        $this->actingAs($crew)->getJson(route('my.shoots.live', [$shoot, 'since' => $first['version']]))
            ->assertExactJson(['changed' => false, 'version' => $first['version']]);

        $this->travel(2)->seconds();
        $section = $script->sections()->first();
        $section->body = '<p>New words</p>';
        $section->save();

        $next = $this->actingAs($crew)->getJson(route('my.shoots.live', [$shoot, 'since' => $first['version']]))->json();
        $this->assertTrue($next['changed']);
        $this->assertNotSame($rev, $next['scripts'][0]['rev']);
        $this->assertStringContainsString('New words', $next['scripts'][0]['sections'][0]['body']);
    }

    public function test_the_live_feed_is_for_the_crew_only(): void
    {
        $shoot = $this->shoot();
        $stranger = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($stranger)->getJson(route('my.shoots.live', $shoot))->assertNotFound();
    }
    public function test_recording_a_scripts_video_marks_the_script_completed(): void
    {
        $client = Client::create(['name' => 'Squad Architects']);
        $shoot = $this->shoot(['client_id' => $client->id]);
        $crew = $this->crewMember($shoot);
        ShootRun::start($shoot, $crew);
        $draft = $this->script($client, 'Italian marble');
        $archived = $this->script($client, 'Old one', Script::STATUS_ARCHIVED);

        ShootRun::recordVideo($shoot, $crew, 'Italian marble', scriptId: $draft->id);
        ShootRun::recordVideo($shoot, $crew, 'Old one', scriptId: $archived->id);

        $this->assertSame(Script::STATUS_COMPLETED, $draft->fresh()->status);
        $this->assertNotNull($draft->fresh()->shot_at);
        $this->assertSame(Script::STATUS_ARCHIVED, $archived->fresh()->status);
    }
}
