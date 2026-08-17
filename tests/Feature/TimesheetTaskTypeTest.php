<?php

namespace Tests\Feature;

use App\Models\TaxonomyTerm;
use App\Models\TimesheetEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task types come from master data now rather than a constant.
 *
 * The contract that matters is the SLUG: entries store it, so a term can be
 * renamed freely but its slug is what fifteen hundred logged hours point at.
 */
class TimesheetTaskTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TimesheetEntry::flushTaskTypes();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_the_built_in_four_are_seeded_as_master_data(): void
    {
        $slugs = TaxonomyTerm::where('type', TaxonomyTerm::TYPE_TASK_TYPE)->pluck('slug')->sort()->values();

        $this->assertSame(['editing', 'other', 'posting', 'shooting'], $slugs->all());
    }

    public function test_a_new_task_type_is_offered_on_the_timesheet(): void
    {
        $this->actingAs($this->admin())->post(route('taxonomy.store'), [
            'type' => TaxonomyTerm::TYPE_TASK_TYPE,
            'name' => 'Client Meeting',
            'is_active' => '1',
        ])->assertSessionHasNoErrors();

        TimesheetEntry::flushTaskTypes();

        $this->assertArrayHasKey('client-meeting', TimesheetEntry::taskTypes());
    }

    public function test_an_employee_can_log_against_a_studio_added_type(): void
    {
        TaxonomyTerm::create([
            'type' => TaxonomyTerm::TYPE_TASK_TYPE,
            'name' => 'Client Meeting',
            'slug' => 'client-meeting',
            'is_active' => true,
        ]);
        TimesheetEntry::flushTaskTypes();

        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($user)->post(route('my.timesheet.store'), [
            'worked_on' => now()->toDateString(),
            'task' => 'Kickoff call with SVA',
            'task_type' => 'client-meeting',
            'venture' => 'All / Multiple Clients',
            'minutes' => 60,
        ])->assertSessionHasNoErrors();

        $this->assertSame('client-meeting', TimesheetEntry::sole()->task_type);
    }

    public function test_a_type_that_is_not_on_the_list_is_refused(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($user)->post(route('my.timesheet.store'), [
            'worked_on' => now()->toDateString(),
            'task' => 'Something',
            'task_type' => 'not-a-real-type',
            'venture' => 'All / Multiple Clients',
            'minutes' => 30,
        ])->assertSessionHasErrors('task_type');
    }

    public function test_renaming_a_type_relabels_the_hours_already_logged(): void
    {
        $entry = TimesheetEntry::create([
            'user_id' => User::factory()->create()->id,
            'worked_on' => now()->toDateString(),
            'task' => 'Cut the reel',
            'task_type' => 'posting',
            'minutes' => 45,
        ]);

        TaxonomyTerm::where('type', TaxonomyTerm::TYPE_TASK_TYPE)
            ->where('slug', 'posting')
            ->update(['name' => 'Publishing']);

        TimesheetEntry::flushTaskTypes();

        // The slug never moved, so the entry follows its term's new name.
        $this->assertSame('Publishing', $entry->fresh()->taskTypeLabel());
    }

    public function test_a_retired_type_still_names_the_hours_logged_against_it(): void
    {
        $entry = TimesheetEntry::create([
            'user_id' => User::factory()->create()->id,
            'worked_on' => now()->toDateString(),
            'task' => 'Old work',
            'task_type' => 'posting',
            'minutes' => 30,
        ]);

        TaxonomyTerm::where('type', TaxonomyTerm::TYPE_TASK_TYPE)
            ->where('slug', 'posting')
            ->update(['is_active' => false]);

        TimesheetEntry::flushTaskTypes();

        // Retired from the picker, but the past entry must say what it was
        // rather than being quietly relabelled "Other Task".
        $this->assertArrayNotHasKey('posting', TimesheetEntry::taskTypes());
        $this->assertSame('Posting', $entry->fresh()->taskTypeLabel());
    }
}