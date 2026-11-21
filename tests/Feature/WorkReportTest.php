<?php

namespace Tests\Feature;

use App\Models\TimesheetEntry;
use App\Models\Todo;
use App\Models\User;
use App\Support\WorkReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Work Report (day / week / month), and cancelling a video after it was edited.
 */
class WorkReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-19 18:00')); // a Thursday
        $this->withoutVite();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->editor = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Sanjai']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function log(string $day, int $minutes, string $venture, string $type = TimesheetEntry::TASK_EDITING): void
    {
        TimesheetEntry::create(['user_id' => $this->editor->id, 'worked_on' => $day, 'task' => 'Edit', 'task_type' => $type, 'venture' => $venture, 'minutes' => $minutes]);
    }

    private function card(string $title, string $status = Todo::STATUS_STARTED): Todo
    {
        $t = new Todo;
        $t->forceFill(['user_id' => $this->editor->id, 'title' => $title, 'venture' => 'Janet', 'status' => $status, 'starts_on' => today(), 'due_on' => today()])->save();

        return $t;
    }

    public function test_the_week_adds_up_hours_clients_and_missing_days(): void
    {
        $this->log('2026-11-16', 240, 'Janet');                                  // Mon
        $this->log('2026-11-17', 180, 'Zira', TimesheetEntry::TASK_SHOOTING);    // Tue
        $this->log('2026-11-19', 60, 'Janet');                                   // Thu
        $this->log('2026-11-10', 999, 'Old');                                    // last week: not counted

        $r = WorkReport::for('week', '2026-11-19');

        $this->assertSame('2026-11-16', $r->from->toDateString());
        $this->assertSame(480, $r->totals()['minutes']);
        $this->assertSame(['Janet' => 300, 'Zira' => 180], $r->clients()->all());
        $sanjai = $r->people()->firstWhere('user.id', $this->editor->id);
        $this->assertSame(3, $sanjai['days_logged']);
        $this->assertSame(['2026-11-18'], $sanjai['missing']->all()); // Wed; Fri–Sun are still to come
        $this->assertSame(480, $r->daily()->sum());

        $this->actingAs($this->admin)->get(route('work-report', ['period' => 'week', 'date' => '2026-11-19']))
            ->assertOk()->assertSee('Work Report')->assertSee('Sanjai')->assertSee('8h')->assertSee('Janet');
        $this->actingAs($this->admin)->get(route('work-report', ['period' => 'day']))->assertOk();
        $this->actingAs($this->admin)->get(route('work-report', ['period' => 'month']))->assertOk()->assertSee('November 2026');
    }

    public function test_an_edited_video_needs_a_reason_to_cancel_and_the_report_shows_it(): void
    {
        $card = $this->card('Janet - Diwali offer');
        $card->moveTo(Todo::STATUS_COMPLETED, $this->editor); // sent for review

        $this->actingAs($this->editor)->postJson(route('my.editing.act', $card), ['action' => 'remove'])->assertStatus(422);
        $this->actingAs($this->admin)->postJson(route('my.editing.act', $card), ['action' => 'remove', 'note' => 'Client dropped the offer'])->assertOk();

        $this->assertSame(Todo::STATUS_CANCELLED, $card->fresh()->status);
        $c = WorkReport::for('day', null)->videos()['cancelled']->first();
        $this->assertTrue($c['edited']);
        $this->assertSame('Client dropped the offer', $c['reason']);
        $this->assertSame(1, WorkReport::for('day', null)->videos()['done']->count());

        $this->actingAs($this->admin)->get(route('work-report', ['period' => 'day']))->assertOk()->assertSee('Client dropped the offer')->assertSee('after editing');
    }

    public function test_a_video_not_yet_edited_is_still_removed_without_a_reason(): void
    {
        $card = $this->card('Added by mistake');
        $this->actingAs($this->editor)->postJson(route('my.editing.act', $card), ['action' => 'remove'])->assertOk();
        $this->assertSame(Todo::STATUS_CANCELLED, $card->fresh()->status);
        $this->assertFalse(WorkReport::for('day', null)->videos()['cancelled']->first()['edited']);
    }

    public function test_the_report_needs_the_timesheets_permission(): void
    {
        $this->actingAs($this->editor)->get(route('work-report'))->assertForbidden();
    }
}
