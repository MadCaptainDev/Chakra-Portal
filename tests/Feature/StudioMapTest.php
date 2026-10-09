<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Proposal;
use App\Models\Shoot;
use App\Models\ShootCrew;
use App\Models\ShootVideo;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Services\ShootRun;
use App\Services\StudioMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudioMapTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function building(string $name): array
    {
        return collect(StudioMap::snapshot()['buildings'])->firstWhere('name', $name);
    }

    public function test_only_admins_reach_the_map(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))
            ->get(route('studio-map'))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))
            ->get(route('studio-map.data'))->assertForbidden();

        $this->actingAs($this->admin())->get(route('studio-map'))
            ->assertOk()
            ->assertSee('Studio Map')
            ->assertSee('data-studio-map-snapshot', false);
    }

    public function test_the_data_endpoint_returns_the_snapshot(): void
    {
        Client::factory()->create(['name' => 'Janet', 'is_active' => true]);

        $this->actingAs($this->admin())->getJson(route('studio-map.data'))
            ->assertOk()
            ->assertJsonStructure(['generated_at', 'totals' => ['owed', 'overdue', 'shoots_today', 'rolling', 'hours_month', 'waiting_on_clients'], 'buildings' => [['id', 'per_hour', 'invoiced_month', 'content']], 'day' => ['from', 'now', 'shoots', 'events'], 'team', 'attention'])
            ->assertJsonPath('buildings.0.name', 'Janet');
    }

    public function test_an_overdue_invoice_paints_the_building_red_whatever_else_is_on(): void
    {
        $client = Client::factory()->create(['name' => 'Janet', 'is_active' => true]);
        Invoice::factory()->create([
            'client_id' => $client->id, 'total' => 7500,
            'status' => Invoice::STATUS_UNPAID, 'due_date' => today()->subDays(12),
        ]);
        Shoot::create(['title' => 'Janet reels', 'client_id' => $client->id, 'starts_at' => now()->setTime(18, 0), 'status' => Shoot::STATUS_CONFIRMED]);

        $b = $this->building('Janet');
        $this->assertSame('overdue', $b['state']);
        $this->assertSame(7500.0, (float) $b['owed']);
        $this->assertSame(12, $b['overdue_days']);
        $this->assertCount(1, $b['shoots_today']);
    }

    public function test_a_shoot_today_brings_its_crew_onto_the_map(): void
    {
        $client = Client::factory()->create(['name' => 'Zira', 'is_active' => true]);
        $shoot = Shoot::create(['title' => 'Zira launch', 'client_id' => $client->id, 'starts_at' => now()->setTime(17, 0), 'status' => Shoot::STATUS_CONFIRMED]);
        $crew = User::factory()->create(['name' => 'Sanjai K']);
        ShootCrew::create(['shoot_id' => $shoot->id, 'user_id' => $crew->id]);

        $snapshot = StudioMap::snapshot();

        $this->assertSame('shoot', collect($snapshot['buildings'])->firstWhere('name', 'Zira')['state']);
        $this->assertSame(['Sanjai K'], $snapshot['day']['shoots'][0]['crew']);
        $this->assertSame($client->id, $snapshot['day']['shoots'][0]['client_id']);
        $this->assertSame(1, $snapshot['totals']['shoots_today']);
    }

    public function test_a_rolling_shoot_outranks_a_planned_one(): void
    {
        $client = Client::factory()->create(['name' => 'Zira', 'is_active' => true]);
        Shoot::create(['title' => 'Zira launch', 'client_id' => $client->id, 'starts_at' => now()->subHour(), 'status' => Shoot::STATUS_CONFIRMED])
            ->forceFill(['started_at' => now()->subHour()])->save();

        $this->assertSame('rolling', $this->building('Zira')['state']);
    }

    public function test_work_sent_to_the_client_shows_as_waiting(): void
    {
        $client = Client::factory()->create(['name' => 'Zira', 'is_active' => true]);
        Proposal::create(['client_id' => $client->id, 'title' => 'Plan', 'status' => Proposal::STATUS_SENT, 'sections' => []]);
        Proposal::create(['client_id' => $client->id, 'title' => 'Draft', 'status' => Proposal::STATUS_DRAFT, 'sections' => []]);

        $b = $this->building('Zira');
        $this->assertSame('waiting', $b['state']);
        $this->assertSame(1, $b['proposals_waiting']);
    }

    public function test_hours_join_through_the_venture_not_the_name(): void
    {
        $client = Client::factory()->create(['name' => 'SVA Silks and Readymades', 'notion_venture' => 'SVA Silks', 'is_active' => true]);
        TimesheetEntry::create([
            'user_id' => $this->admin()->id, 'worked_on' => today()->toDateString(), 'task' => 'Editing',
            'task_type' => 'editing', 'venture' => 'SVA Silks', 'minutes' => 150,
        ]);

        $this->assertSame(2.5, $this->building($client->name)['hours']);
    }

    public function test_an_inactive_client_appears_only_while_it_still_owes(): void
    {
        $paused = Client::factory()->create(['name' => 'Paused', 'is_active' => false]);
        Client::factory()->create(['name' => 'Gone', 'is_active' => false]);
        Invoice::factory()->create([
            'client_id' => $paused->id, 'total' => 1000,
            'status' => Invoice::STATUS_UNPAID, 'due_date' => today()->addDays(5),
        ]);

        $names = collect(StudioMap::snapshot()['buildings'])->pluck('name');
        $this->assertTrue($names->contains('Paused'));
        $this->assertFalse($names->contains('Gone'));
        $this->assertSame('owes', $this->building('Paused')['state']);
    }

    // ——— The day: what the live feed and replay play ———

    public function test_todays_run_becomes_a_timeline_of_events_in_order(): void
    {
        $this->travelTo(today()->setTime(9, 0));
        $client = Client::factory()->create(['name' => 'Zira', 'is_active' => true]);
        $shoot = Shoot::create(['title' => 'Zira launch', 'client_id' => $client->id, 'starts_at' => now()->setTime(10, 0), 'status' => Shoot::STATUS_CONFIRMED]);
        $crew = User::factory()->create(['name' => 'Sanjai K']);
        ShootCrew::create(['shoot_id' => $shoot->id, 'user_id' => $crew->id]);

        $this->travelTo(today()->setTime(9, 40));
        ShootRun::reached($shoot, $crew);
        $this->travelTo(today()->setTime(10, 5));
        ShootRun::start($shoot->fresh(), $crew);
        $this->travelTo(today()->setTime(10, 30));
        ShootVideo::create(['shoot_id' => $shoot->id, 'recorded_by_id' => $crew->id, 'name' => 'Product close-up', 'photo_path' => 'uploads/shoot-videos/x.jpg', 'position' => 1]);
        $this->travelTo(today()->setTime(12, 0));
        ShootRun::finish($shoot->fresh(), $crew);

        $day = StudioMap::snapshot()['day'];

        $this->assertSame(['reached', 'rolling', 'video', 'wrapped'], array_column($day['events'], 'type'));
        $video = $day['events'][2];
        $this->assertSame('Product close-up', $video['title']);
        $this->assertSame('Sanjai K', $video['who']);
        $this->assertStringEndsWith('uploads/shoot-videos/x.jpg', $video['photo']);
        $this->assertSame('10:30 AM', $video['time']);
        $this->assertSame(today()->setTime(9, 40)->getTimestampMs(), $day['shoots'][0]['reached_at']);
        $this->assertSame(today()->setTime(6, 0)->getTimestampMs(), $day['from']);
        $this->assertCount(4, array_unique(array_column($day['events'], 'id')), 'every event has its own id for the feed');
    }

    public function test_the_team_carries_hours_logged_today(): void
    {
        $staff = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Nitis']);
        User::factory()->create(['role' => User::ROLE_CLIENT, 'name' => 'A client login']);
        TimesheetEntry::create([
            'user_id' => $staff->id, 'worked_on' => today()->toDateString(), 'task' => 'Editing',
            'task_type' => 'editing', 'venture' => 'SVA Silks', 'minutes' => 90,
        ]);

        $team = collect(StudioMap::snapshot()['team'])->keyBy('name');

        $this->assertSame(1.5, $team['Nitis']['hours_today']);
        $this->assertFalse($team->has('A client login'), 'clients are not on the team');
    }

    public function test_money_lens_figures_come_from_the_same_join_as_insights(): void
    {
        $client = Client::factory()->create(['name' => 'SVA Silks and Readymades', 'notion_venture' => 'SVA Silks', 'is_active' => true]);
        TimesheetEntry::create([
            'user_id' => $this->admin()->id, 'worked_on' => today()->toDateString(), 'task' => 'Editing',
            'task_type' => 'editing', 'venture' => 'SVA Silks', 'minutes' => 120,
        ]);
        Invoice::factory()->create(['client_id' => $client->id, 'total' => 3000, 'invoice_date' => today(), 'status' => Invoice::STATUS_PAID]);

        $b = $this->building($client->name);
        $this->assertSame(3000.0, (float) $b['invoiced_month']);
        $this->assertSame(1500, $b['per_hour']);
        $this->assertNull($b['content'], 'no content account, nothing to forecast');
    }
}
