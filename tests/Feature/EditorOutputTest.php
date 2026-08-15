<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Support\EditorThroughput;
use App\Support\TimesheetAnomalies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EditorOutputTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function entry(User $user, array $overrides = []): TimesheetEntry
    {
        return TimesheetEntry::create(array_merge([
            'user_id' => $user->id,
            'worked_on' => '2026-05-04',
            'task' => 'Editing',
            'task_type' => TimesheetEntry::TASK_EDITING,
            'venture' => 'SVA Silks',
            'minutes' => 120,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function item(string $editor, array $overrides = []): ContentItem
    {
        static $n = 0;
        $n++;

        return ContentItem::create(array_merge([
            'source' => 'reel',
            'notion_page_id' => 'page-'.$n,
            'title' => 'Reel '.$n,
            'editor' => $editor,
            'tier' => 'Low Effort & Work',
            'status' => 'Published',
            'published_date' => '2026-05-10',
        ], $overrides));
    }

    // ——— Reading Notion's tiers ———

    public function test_the_tier_typo_in_notion_is_read_rather_than_corrected(): void
    {
        // The Reel planner says "Hight Effort & Work" and has for months. This
        // reads Notion; it does not get to rename things in it.
        $this->assertSame(EditorThroughput::TIER_HIGH, EditorThroughput::tierOf('Hight Effort & Work'));
        // And still works the day somebody fixes the spelling.
        $this->assertSame(EditorThroughput::TIER_HIGH, EditorThroughput::tierOf('High Effort & Work'));
        $this->assertSame(EditorThroughput::TIER_MEDIUM, EditorThroughput::tierOf('Medium Effort & Work'));
        $this->assertSame(EditorThroughput::TIER_LOW, EditorThroughput::tierOf('Low Effort & Work'));
        $this->assertSame(EditorThroughput::TIER_NONE, EditorThroughput::tierOf(null));
        $this->assertSame(EditorThroughput::TIER_NONE, EditorThroughput::tierOf('  '));
    }

    // ——— Joining two systems that do not know about each other ———

    public function test_notion_first_names_are_matched_to_portal_logins(): void
    {
        $user = User::factory()->create(['name' => 'Sanjai Kumar']);
        $this->entry($user, ['minutes' => 240]);
        $this->item('Sanjai');
        $this->item('Sanjai');

        $row = EditorThroughput::between(Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'))['rows']
            ->firstWhere('label', 'Sanjai Kumar');

        // Notion carries "Sanjai", the portal carries "Sanjai Kumar".
        $this->assertSame(2, $row['items']);
        $this->assertSame(240, $row['minutes']);
        $this->assertSame(120, $row['minutesPerItem']);
    }

    public function test_a_co_edited_item_is_credited_to_nobody(): void
    {
        $user = User::factory()->create(['name' => 'Sanjai']);
        $this->entry($user);
        $this->item('Sanjai');
        $this->item('Aron, Sanjai');

        $result = EditorThroughput::between(Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));

        // Crediting both would invent output; crediting the first would rob
        // the second. It is counted and set aside.
        $this->assertSame(1, $result['rows']->firstWhere('label', 'Sanjai')['items']);
        $this->assertSame(1, $result['shared']);
    }

    public function test_an_editor_notion_knows_and_the_portal_does_not_still_appears(): void
    {
        $this->item('Keerthi');

        $row = EditorThroughput::between(Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'))['rows']
            ->firstWhere('label', 'Keerthi');

        $this->assertNotNull($row);
        $this->assertNull($row['user']);
        // No hours to divide by, so no rate is invented.
        $this->assertNull($row['minutesPerItem']);
    }

    public function test_someone_with_hours_and_no_tracked_output_shows_no_rate(): void
    {
        $user = User::factory()->create(['name' => 'Nitis']);
        $this->entry($user, ['minutes' => 600]);

        $row = EditorThroughput::between(Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'))['rows']
            ->firstWhere('label', 'Nitis');

        // The interesting case: either their planner is not synced or the work
        // is not reaching the board. Either way a rate would be a lie.
        $this->assertSame(0, $row['items']);
        $this->assertSame(600, $row['minutes']);
        $this->assertNull($row['minutesPerItem']);
    }

    public function test_cancelled_editing_time_is_not_counted_against_output(): void
    {
        $user = User::factory()->create(['name' => 'Gokul']);
        $this->entry($user, ['minutes' => 120]);
        $this->entry($user, ['minutes' => 600])->forceFill(['status' => TimesheetEntry::STATUS_CANCELLED])->save();
        $this->item('Gokul');

        $row = EditorThroughput::between(Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'))['rows']
            ->firstWhere('label', 'Gokul');

        $this->assertSame(120, $row['minutes']);
    }

    public function test_the_tier_mix_is_counted_per_editor(): void
    {
        $user = User::factory()->create(['name' => 'Sanjai']);
        $this->entry($user);
        $this->item('Sanjai', ['tier' => 'Hight Effort & Work']);
        $this->item('Sanjai', ['tier' => 'Medium Effort & Work']);
        $this->item('Sanjai', ['tier' => null]);

        $row = EditorThroughput::between(Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'))['rows']
            ->firstWhere('label', 'Sanjai');

        $this->assertSame(1, $row['tiers'][EditorThroughput::TIER_HIGH]);
        $this->assertSame(1, $row['tiers'][EditorThroughput::TIER_MEDIUM]);
        $this->assertSame(1, $row['tiers'][EditorThroughput::TIER_NONE]);
        // Two of three were medium or high.
        $this->assertSame(67, $row['hardShare']);
    }

    public function test_months_are_grouped_without_a_mysql_only_date_function(): void
    {
        $user = User::factory()->create(['name' => 'Gokul']);
        $this->entry($user, ['worked_on' => '2026-04-10', 'minutes' => 60]);
        $this->entry($user, ['worked_on' => '2026-05-10', 'minutes' => 120]);
        $this->item('Gokul', ['published_date' => '2026-04-12']);
        $this->item('Gokul', ['published_date' => '2026-05-12']);

        // Grouping in PHP is what makes this pass on SQLite as well as MySQL.
        $months = EditorThroughput::between(Carbon::parse('2026-04-01'), Carbon::parse('2026-05-31'))['months'];

        $this->assertSame(['2026-04', '2026-05'], $months->pluck('key')->all());
        $this->assertSame(60, $months->firstWhere('key', '2026-04')['minutes']);
        $this->assertSame(1, $months->firstWhere('key', '2026-05')['items']);
    }

    // ——— What cannot be true ———

    public function test_a_twenty_four_hour_entry_is_flagged_as_a_calendar_span(): void
    {
        $user = User::factory()->create();
        $this->entry($user, ['minutes' => 1440]);

        $flags = TimesheetAnomalies::between(Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));

        $this->assertTrue($flags->contains('kind', 'full_day'));
        $this->assertSame(TimesheetAnomalies::SEVERITY_HIGH, $flags->firstWhere('kind', 'full_day')['severity']);
    }

    public function test_a_day_holding_more_than_a_day_is_flagged(): void
    {
        $user = User::factory()->create();
        $this->entry($user, ['minutes' => 600]);
        $this->entry($user, ['minutes' => 600]);

        $flag = TimesheetAnomalies::between(Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'))
            ->firstWhere('kind', 'impossible_day');

        $this->assertNotNull($flag);
        $this->assertSame(1200, $flag['minutes']);
    }

    public function test_a_long_but_possible_entry_is_only_worth_a_look(): void
    {
        $user = User::factory()->create();
        // A shoot day genuinely runs this long, so it must not read as a lie.
        $this->entry($user, ['minutes' => 780, 'task_type' => TimesheetEntry::TASK_SHOOTING]);

        $flag = TimesheetAnomalies::between(Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'))
            ->firstWhere('kind', 'long_entry');

        $this->assertSame(TimesheetAnomalies::SEVERITY_MEDIUM, $flag['severity']);
    }

    public function test_entries_claiming_the_same_hour_are_flagged(): void
    {
        $user = User::factory()->create();
        $this->entry($user, ['started_at' => '09:00', 'ended_at' => '11:00', 'minutes' => 120]);
        $this->entry($user, ['started_at' => '10:00', 'ended_at' => '12:00', 'minutes' => 120]);

        $this->assertTrue(
            TimesheetAnomalies::between(Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'))
                ->contains('kind', 'overlap')
        );
    }

    public function test_back_to_back_entries_do_not_count_as_overlapping(): void
    {
        $user = User::factory()->create();
        $this->entry($user, ['started_at' => '09:00', 'ended_at' => '11:00', 'minutes' => 120]);
        $this->entry($user, ['started_at' => '11:00', 'ended_at' => '13:00', 'minutes' => 120]);

        $this->assertFalse(
            TimesheetAnomalies::between(Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'))
                ->contains('kind', 'overlap')
        );
    }

    public function test_an_entry_with_no_times_cannot_overlap_anything(): void
    {
        $user = User::factory()->create();
        // A duration with no clock times makes no claim about *when*.
        $this->entry($user, ['minutes' => 240]);
        $this->entry($user, ['minutes' => 240]);

        $this->assertFalse(
            TimesheetAnomalies::between(Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'))
                ->contains('kind', 'overlap')
        );
    }

    public function test_one_entry_flagged_twice_is_counted_once_against_their_hours(): void
    {
        $user = User::factory()->create();
        // 24 hours AND overlapping its neighbour: two problems, one lot of
        // minutes. Summing both used to push the share above 100%.
        $this->entry($user, ['started_at' => '09:00', 'ended_at' => '11:00', 'minutes' => 1440]);
        $this->entry($user, ['started_at' => '10:00', 'ended_at' => '12:00', 'minutes' => 60]);

        $from = Carbon::parse('2026-05-01');
        $to = Carbon::parse('2026-05-31');
        $impact = TimesheetAnomalies::editingImpactByUser(TimesheetAnomalies::between($from, $to), $from, $to);

        $this->assertLessThanOrEqual(1.0, $impact[$user->id]['share']);
    }

    public function test_flags_on_shoot_days_do_not_taint_editing_hours(): void
    {
        $user = User::factory()->create();
        $this->entry($user, ['minutes' => 1440, 'task_type' => TimesheetEntry::TASK_SHOOTING]);
        $this->entry($user, ['minutes' => 120]);

        $from = Carbon::parse('2026-05-01');
        $to = Carbon::parse('2026-05-31');
        $impact = TimesheetAnomalies::editingImpactByUser(TimesheetAnomalies::between($from, $to), $from, $to);

        // Their editing hours are perfectly usable.
        $this->assertArrayNotHasKey($user->id, $impact);
    }

    public function test_hours_with_no_client_are_reported_but_not_called_wrong(): void
    {
        $user = User::factory()->create();
        $this->entry($user, ['venture' => null, 'minutes' => 300]);

        $flag = TimesheetAnomalies::between(Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'))
            ->firstWhere('kind', 'unattributed');

        $this->assertSame(TimesheetAnomalies::SEVERITY_LOW, $flag['severity']);
        $this->assertSame(300, $flag['minutes']);
    }

    // ——— Who may open it ———

    public function test_an_admin_sees_the_screen(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $user = User::factory()->create(['name' => 'Sanjai']);
        $this->entry($user);
        $this->item('Sanjai');

        $this->actingAs($admin)->get(route('editors.index'))
            ->assertOk()
            ->assertSee('Editor Output')
            ->assertSee('Sanjai');
    }

    public function test_an_employee_is_refused(): void
    {
        // This is the screen that ranks people against each other. Who sees it
        // is the owner's call, not a permission checkbox.
        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))
            ->get(route('editors.index'))
            ->assertForbidden();
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(route('editors.index'))->assertRedirect(route('login'));
    }

    public function test_a_nonsense_period_falls_back_rather_than_erroring(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get(route('editors.index', ['months' => '9999']))->assertOk();
        $this->actingAs($admin)->get(route('editors.index', ['months' => 'banana']))->assertOk();
    }
}
