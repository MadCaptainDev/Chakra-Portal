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
     * @param  array<string, mixed>  $graph
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

    public function test_every_range_is_built_as_whole_weeks(): void
    {
        $graphs = ContributionGraph::forTeam(Carbon::parse('2026-08-12'));

        $this->assertSame(array_keys(ContributionGraph::RANGES), array_keys($graphs));

        foreach ($graphs as $graph) {
            foreach ($graph['weeks'] as $week) {
                $this->assertCount(7, $week);
            }
        }
    }

    public function test_this_week_is_a_single_column(): void
    {
        $graphs = ContributionGraph::forTeam(Carbon::parse('2026-08-12'));

        $this->assertCount(1, $graphs[ContributionGraph::WEEK]['weeks']);
        $this->assertCount(53, $graphs[ContributionGraph::YEAR]['weeks']);
    }

    public function test_this_month_shows_only_that_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12'));

        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $this->entry($user, '2026-07-31', 300);
        $this->entry($user, '2026-08-03', 120);

        $month = ContributionGraph::forTeam()[ContributionGraph::MONTH];

        // The grid still starts on a Sunday, but the days either side belong to
        // another month and must not be drawn or counted.
        $this->assertNull($this->cellFor($month, '2026-07-31'));
        $this->assertNotNull($this->cellFor($month, '2026-08-03'));
        $this->assertSame(120, $month['total']);
        $this->assertSame('August 2026', $month['caption']);

        Carbon::setTestNow();
    }

    public function test_each_range_bands_against_its_own_busiest_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12'));  // a Wednesday

        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $this->entry($user, '2026-03-04', 900);   // a monster day months ago
        $this->entry($user, '2026-08-11', 120);   // an ordinary day this week

        $graphs = ContributionGraph::forTeam();

        // Against March the Tuesday is a faint square; on its own week it is
        // the busiest thing that happened.
        $this->assertSame(1, $this->cellFor($graphs[ContributionGraph::YEAR], '2026-08-11')['level']);
        $this->assertSame(4, $this->cellFor($graphs[ContributionGraph::WEEK], '2026-08-11')['level']);

        Carbon::setTestNow();
    }

    public function test_everybodys_hours_land_on_the_same_square(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12'));

        $one = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $two = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->entry($one, '2026-08-10', 120);
        $this->entry($two, '2026-08-10', 180);

        $year = ContributionGraph::forTeam()[ContributionGraph::YEAR];

        // The picture is the studio's, not one person's.
        $this->assertSame(300, $this->cellFor($year, '2026-08-10')['minutes']);
        $this->assertSame(300, $year['total']);

        Carbon::setTestNow();
    }

    public function test_a_day_with_any_work_is_never_the_same_as_an_empty_one(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12'));

        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $this->entry($user, '2026-08-10', 600);
        $this->entry($user, '2026-08-11', 15);

        $year = ContributionGraph::forTeam()[ContributionGraph::YEAR];

        $this->assertSame(4, $this->cellFor($year, '2026-08-10')['level']);
        // A quarter of an hour against a ten-hour day still has to show.
        $this->assertSame(1, $this->cellFor($year, '2026-08-11')['level']);
        $this->assertSame(0, $this->cellFor($year, '2026-08-09')['level']);

        Carbon::setTestNow();
    }

    public function test_cancelled_work_leaves_the_square_empty(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12'));

        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $this->entry($user, '2026-08-10', 240)
            ->forceFill(['status' => TimesheetEntry::STATUS_CANCELLED])
            ->save();

        $year = ContributionGraph::forTeam()[ContributionGraph::YEAR];

        $this->assertSame(0, $this->cellFor($year, '2026-08-10')['minutes']);
        $this->assertSame(0, $year['daysWorked']);

        Carbon::setTestNow();
    }

    public function test_days_that_have_not_happened_yet_are_left_blank(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12'));  // a Wednesday

        $graphs = ContributionGraph::forTeam();

        foreach ([ContributionGraph::WEEK, ContributionGraph::YEAR] as $range) {
            // The rest of this week exists as a column but not as squares -- a
            // Friday that has not arrived is not a quiet day.
            $this->assertNull($this->cellFor($graphs[$range], '2026-08-14'));
            $this->assertNotNull($this->cellFor($graphs[$range], '2026-08-12'));
        }

        Carbon::setTestNow();
    }

    public function test_the_busiest_day_is_named(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12'));

        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $this->entry($user, '2026-08-03', 120);
        $this->entry($user, '2026-08-05', 480);

        $year = ContributionGraph::forTeam()[ContributionGraph::YEAR];

        $this->assertSame('2026-08-05', $year['busiest']['date']->toDateString());
        $this->assertSame(480, $year['busiest']['minutes']);

        Carbon::setTestNow();
    }

    public function test_an_empty_studio_produces_a_grid_and_no_busiest_day(): void
    {
        foreach (ContributionGraph::forTeam(Carbon::parse('2026-08-12')) as $graph) {
            $this->assertNull($graph['busiest']);
            $this->assertSame(0, $graph['total']);
            $this->assertSame(0, $graph['daysWorked']);
            $this->assertNotEmpty($graph['weeks']);
        }
    }

    public function test_the_admin_dashboard_offers_every_period(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12'));

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $this->entry($employee, '2026-08-10', 240);

        $response = $this->actingAs($admin)->get(route('dashboard'))->assertOk();

        $response->assertSee('Work logged')->assertSee('Busiest was Mon 10 Aug');

        foreach (ContributionGraph::RANGES as $label) {
            $response->assertSee($label);
        }

        Carbon::setTestNow();
    }
}
