<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Shoot;
use App\Models\ShootCrew;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Past shoots per crew member: My Shoots → "Your past shoots" and
 * Shoots → Crew record. See App\Support\CrewShootRecord.
 */
class CrewShootRecordTest extends TestCase
{
    use RefreshDatabase;

    private User $aron;

    private User $nitis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(today()->setDay(20)->setTime(10, 0));
        $this->aron = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Aron Sham']);
        $this->nitis = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Nitis']);
    }

    private function shoot(string $title, $when, array $crew, string $status = Shoot::STATUS_COMPLETED): Shoot
    {
        $shoot = Shoot::create(['title' => $title, 'starts_at' => $when, 'status' => $status, 'location' => 'Trichy',
            'client_id' => Client::factory()->create(['name' => 'SVA Silks'])->id]);
        foreach ($crew as $user) {
            ShootCrew::create(['shoot_id' => $shoot->id, 'user_id' => $user->id]);
        }

        return $shoot;
    }

    public function test_a_crew_member_sees_their_own_past_shoots_month_by_month(): void
    {
        $this->shoot('SVA Diwali shoot', today()->subDays(3), [$this->aron, $this->nitis]);
        $this->shoot('Janet clinic shoot', today()->subMonthNoOverflow()->setDay(5), [$this->aron]);
        $this->shoot('Rained out', today()->subDays(2), [$this->aron], Shoot::STATUS_CANCELLED);
        $this->shoot('Nitis alone', today()->subDays(4), [$this->nitis]);
        $this->shoot('Old Notion one', today()->subDays(6), [$this->aron], Shoot::STATUS_PLANNED);
        $this->shoot('Tomorrow', today()->addDay(), [$this->aron], Shoot::STATUS_CONFIRMED);

        $this->actingAs($this->aron)->get(route('my.shoots'))
            ->assertOk()
            ->assertSee('Your past shoots')
            ->assertSeeInOrder([today()->format('F Y'), 'SVA Diwali shoot', 'With Nitis', 'Old Notion one', 'Not marked done', today()->subMonthNoOverflow()->format('F Y'), 'Janet clinic shoot', 'On your own'])
            ->assertDontSee('Rained out')
            ->assertDontSee('Nitis alone')
            ->assertSee('mostly for SVA Silks')
            ->assertViewHas('past', fn ($past) => $past->count() === 3);
    }

    public function test_each_past_shoot_shows_the_videos_that_came_out_of_it(): void
    {
        $notion = \App\Models\NotionShoot::factory()->create(['video_count' => '4']);
        $shoot = $this->shoot('SVA Diwali shoot', today()->subDays(3), [$this->aron]);
        $shoot->forceFill(['notion_shoot_id' => $notion->id])->save();
        \App\Models\ContentItem::factory()->create(['source' => 'reel', 'title' => 'SVA - Silk saree twirl', 'status' => 'Published', 'notion_shoot_id' => $notion->id, 'published_date' => today()->subDay()]);
        \App\Models\ContentItem::factory()->create(['source' => 'reel', 'title' => 'SVA - Kurti closeups', 'status' => 'To Be Edited', 'notion_shoot_id' => $notion->id, 'published_date' => today()->addDays(4)]);
        \App\Models\ContentItem::factory()->create(['source' => 'reel', 'title' => 'Deleted in Notion', 'notion_shoot_id' => $notion->id, 'notion_missing_since' => now()]);
        \App\Models\ShootVideo::create(['shoot_id' => $shoot->id, 'recorded_by_id' => $this->aron->id, 'name' => 'Lehenga walk', 'position' => 1]);
        $this->shoot('Janet clinic shoot', today()->subDays(5), [$this->aron]);

        $this->actingAs($this->aron)->get(route('my.shoots'))
            ->assertOk()
            ->assertSeeInOrder(['SVA Diwali shoot', '2 videos of 4 planned', '1 published', 'From the Reel Planner', 'SVA - Silk saree twirl', 'Went out', 'Published', 'SVA - Kurti closeups', 'Goes out', 'To Be Edited', 'Logged on the day', 'Lehenga walk', 'Aron Sham'])
            ->assertSee('2 videos came out of these shoots')
            ->assertSeeInOrder(['Janet clinic shoot', 'No videos linked yet'])
            ->assertDontSee('Deleted in Notion');
    }

    public function test_managers_see_everyones_record_and_one_persons_list(): void
    {
        $this->shoot('SVA Diwali shoot', today()->subDays(3), [$this->aron, $this->nitis]);
        $this->shoot('Janet clinic shoot', today()->subMonthNoOverflow()->setDay(5), [$this->aron]);
        $this->shoot('Rained out', today()->subDays(2), [$this->aron], Shoot::STATUS_CANCELLED);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get(route('shoots.crew-record'))
            ->assertOk()
            ->assertSeeInOrder(['Aron Sham', '1', '1', '2', 'Nitis'])
            ->assertViewHas('team', fn ($team) => $team['people']->firstWhere('name', 'Aron Sham')['total'] === 2
                && $team['people']->firstWhere('name', 'Nitis')['total'] === 1
                && end($team['people']->firstWhere('name', 'Aron Sham')['months']) === 1);

        $this->actingAs($admin)->get(route('shoots.crew-record', ['person' => $this->aron->id]))
            ->assertOk()->assertSee('Janet clinic shoot')->assertDontSee('Rained out');
        $this->actingAs($admin)->get(route('shoots.index'))->assertSee(route('shoots.crew-record'));
    }

    public function test_the_crew_record_needs_shoots_access(): void
    {
        $this->actingAs($this->aron)->get(route('shoots.crew-record'))->assertForbidden();

        UserPermission::create(['user_id' => $this->nitis->id, 'module' => 'shoots', 'ability' => 'view']);
        $this->actingAs($this->nitis)->get(route('shoots.crew-record'))->assertOk();

        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $this->actingAs($this->nitis)->get(route('shoots.crew-record', ['person' => $client->id]))->assertNotFound();
    }
}
