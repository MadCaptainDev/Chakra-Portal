<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\TaxonomyTerm;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Support\TimesheetVenture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One entry, several ventures.
 *
 * The primary stays authoritative -- every hours report reads `venture` and
 * counts the minutes once -- and `ventures` records who else the work was for.
 * Most of this is about that split holding.
 */
class TimesheetVentureTest extends TestCase
{
    use RefreshDatabase;

    private function employee(): User
    {
        return User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
    }

    private function clients(): void
    {
        Client::create(['name' => 'SVA Silks']);
        Client::create(['name' => 'Riya Makeover']);
    }

    private function entryPayload(array $overrides = []): array
    {
        return $overrides + [
            'worked_on' => today()->toDateString(),
            'task' => 'Shared shoot day',
            'task_type' => 'shooting',
            'venture' => 'SVA Silks',
            'minutes' => 120,
        ];
    }

    public function test_an_entry_can_name_several_ventures(): void
    {
        $this->clients();

        $this->actingAs($this->employee())
            ->post(route('my.timesheet.store'), $this->entryPayload([
                'ventures' => ['Riya Makeover'],
            ]))
            ->assertSessionHasNoErrors();

        $entry = TimesheetEntry::sole();

        // Primary first, then the rest.
        $this->assertSame('SVA Silks', $entry->venture);
        $this->assertSame(['SVA Silks', 'Riya Makeover'], $entry->ventureList());
    }

    public function test_the_minutes_are_still_counted_once_against_the_primary(): void
    {
        $this->clients();

        $this->actingAs($this->employee())->post(route('my.timesheet.store'), $this->entryPayload([
            'ventures' => ['Riya Makeover'],
        ]));

        // The conservative reading, and the reason this change is additive:
        // naming a second venture must not silently double a month's hours.
        $this->assertSame(120, (int) TimesheetEntry::sum('minutes'));
        $this->assertSame(1, TimesheetEntry::where('venture', 'SVA Silks')->count());
        $this->assertSame(0, TimesheetEntry::where('venture', 'Riya Makeover')->count());
    }

    public function test_other_creates_a_venture_everyone_can_pick_next_time(): void
    {
        $this->clients();

        $this->actingAs($this->employee())->post(route('my.timesheet.store'), $this->entryPayload([
            'new_venture' => 'Studio showreel',
        ]))->assertSessionHasNoErrors();

        // Stored as master data...
        $this->assertDatabaseHas('taxonomy_terms', [
            'type' => TaxonomyTerm::TYPE_VENTURE,
            'name' => 'Studio showreel',
        ]);

        // ...on the entry...
        $this->assertContains('Studio showreel', TimesheetEntry::sole()->ventureList());

        // ...and on the list the next person sees.
        $this->assertContains('Studio showreel', TimesheetVenture::allowedValues());
    }

    public function test_a_typed_venture_is_not_duplicated_by_different_casing(): void
    {
        $this->clients();
        $employee = $this->employee();

        $this->actingAs($employee)->post(route('my.timesheet.store'), $this->entryPayload([
            'new_venture' => 'Studio Showreel',
        ]));
        $this->actingAs($employee)->post(route('my.timesheet.store'), $this->entryPayload([
            'new_venture' => 'studio showreel',
        ]));

        // The whole point of master data is that one thing has one spelling.
        $this->assertSame(1, TaxonomyTerm::where('type', TaxonomyTerm::TYPE_VENTURE)->count());
    }

    public function test_typing_an_existing_client_name_reuses_it(): void
    {
        $this->clients();

        $this->actingAs($this->employee())->post(route('my.timesheet.store'), $this->entryPayload([
            'new_venture' => 'riya makeover',
        ]));

        // A client is already a venture; it must not gain a shadow copy.
        $this->assertSame(0, TaxonomyTerm::where('type', TaxonomyTerm::TYPE_VENTURE)->count());
        $this->assertContains('Riya Makeover', TimesheetEntry::sole()->ventureList());
    }

    public function test_an_unknown_extra_venture_is_dropped_not_invented(): void
    {
        $this->clients();

        $this->actingAs($this->employee())->post(route('my.timesheet.store'), $this->entryPayload([
            'ventures' => ['Riya Makeover', 'Not A Real Venture'],
        ]))->assertSessionHasNoErrors();

        $list = TimesheetEntry::sole()->ventureList();

        $this->assertContains('Riya Makeover', $list);
        $this->assertNotContains('Not A Real Venture', $list);
        $this->assertSame(0, TaxonomyTerm::where('type', TaxonomyTerm::TYPE_VENTURE)->count());
    }

    public function test_an_old_single_venture_entry_still_reads_as_a_list(): void
    {
        $entry = TimesheetEntry::create([
            'user_id' => $this->employee()->id,
            'worked_on' => today()->toDateString(),
            'task' => 'Imported row',
            'task_type' => 'editing',
            'venture' => 'SVA Silks',
            'minutes' => 60,
        ]);

        // Fifteen hundred rows predate the column; callers must not have to
        // know when it arrived.
        $this->assertSame(['SVA Silks'], $entry->ventureList());
        $this->assertSame('SVA Silks', $entry->ventureLabel());
    }

    public function test_the_label_counts_the_extras_rather_than_listing_them(): void
    {
        $entry = new TimesheetEntry([
            'venture' => 'SVA Silks',
            'ventures' => ['SVA Silks', 'Riya Makeover', 'Studio showreel'],
        ]);

        $this->assertSame('SVA Silks +2', $entry->ventureLabel());
    }
}