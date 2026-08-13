<?php

namespace Tests\Feature;

use App\Models\TimesheetEntry;
use App\Models\User;
use App\Support\ContributionGraph;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ContributionGraphTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function entry(User $user, string $workedOn, int $minutes, array $overrides = []): TimesheetEntry
    {
        return TimesheetEntry::create(array_merge([
            'user_id' => $user->id,
            'worked_on' => $workedOn,
            'task' => 'Shoot',
            'task_type' => TimesheetEntry::TASK_SHOOTING,
            'minutes' => $minutes,
        ], $overrides));
    }

    /**
     * @return array{date: Carbon, minutes: int, level: int}|null
     */
    private function cellFor(array $graph, string $date): ?array
    {
        foreach ($graph['weeks'] as $week) {
            foreach ($week as $day) {
                if ($day !== null && $day['date']->toDateString() === $date) {
                    return $day;
                }
            }
        }

        return null;
    }

    public function test_the_grid_covers_a_year_of_whole_weeks(): void
    {
        $graph = ContributionGraph::forTeam(Carbon::parse('2026-08-12'));

        $this->assertCount(ContributionGraph::WEEKS, $graph['weeks']);

        foreach ($graph['weeks'] as $week) {
            $this->assertCount(7, $week);
        }
    }

    public function test_everybodys_hours_land_on_the_same_square(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12'));

        $one = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $two = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->entry($one, '2026-08-10', 120);
        $this->entry($two, '2026-08-10', 180);

        $graph = ContributionGraph::forTeam();

        // The picture is the studio's, not one person's.
        $this->assertSame(300, $this->cellFor($graph, '2026-08-10')['minutes']);
        $this->assertSame(300, $graph['total']);

        Carbon::setTestNow();
    }

    public function test_a_day_with_any_work_is_never_the_same_as_an_empty_one(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12'));

        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $this->entry($user, '2026-08-10', 600);
        $this->entry($user, '2026-08-11', 15);

        $graph = ContributionGraph::forTeam();

        $this->assertSame(4, $this->cellFor($graph, '2026-08-10')['level']);
        // A quarter of an hour against a ten-hour day still has to show.
        $this->assertSame(1, $this->cellFor($graph, '2026-08-11')['level']);
        $this->assertSame(0, $this->cellFor($graph, '2026-08-09')['level']);

        Carbon::setTestNow();
    }

    public function test_cancelled_work_leaves_the_square_empty(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12'));

        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $this->entry($user, '2026-08-10', 240)
            ->forceFill(['status' => TimesheetEntry::STATUS_CANCELLED])
            ->save();

        $graph = ContributionGraph::forTeam();

        $this->assertSame(0, $this->cellFor($graph, '2026-08-10')['minutes']);
        $this->assertSame(0, $graph['daysWorked']);

        Carbon::setTestNow();
    }

    public function test_days_that_have_not_happened_yet_are_left_blank(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12'));  // a Wednesday

        $graph = ContributionGraph::forTeam();

        // The rest of this week exists as a column but not as squares -- a
        // Friday that has not arrived is not a quiet day.
        $this->assertNull($this->cellFor($graph, '2026-08-14'));
        $this->assertNotNull($this->cellFor($graph, '2026-08-12'));

        Carbon::setTestNow();
    }

    public function test_the_busiest_day_is_named(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12'));

        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $this->entry($user, '2026-08-03', 120);
        $this->entry($user, '2026-08-05', 480);

        $graph = ContributionGraph::forTeam();

        $this->assertSame('2026-08-05', $graph['busiest']['date']->toDateString());
        $this->assertSame(480, $graph['busiest']['minutes']);

        Carbon::setTestNow();
    }

    public function test_an_empty_studio_produces_a_grid_and_no_busiest_day(): void
    {
        $graph = ContributionGraph::forTeam(Carbon::parse('2026-08-12'));

        $this->assertNull($graph['busiest']);
        $this->assertSame(0, $graph['total']);
        $this->assertSame(0, $graph['daysWorked']);
    }

    public function test_the_admin_dashboard_shows_the_heatmap(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12'));

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $this->entry($employee, '2026-08-10', 240);

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('A year of work')
            ->assertSee('Busiest was Mon 10 Aug 2026');

        Carbon::setTestNow();
    }
}
