<?php

namespace Tests\Feature;

use App\Models\EquipmentItem;
use App\Models\Shoot;
use App\Models\ShootKit;
use App\Models\TaxonomyTerm;
use App\Models\User;
use App\Support\KitAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ShootKitTest extends TestCase
{
    use RefreshDatabase;

    private function crew(array $abilities = ['view', 'create', 'edit', 'delete']): User
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $user->syncPermissions(['shoots' => $abilities, 'equipment' => ['view']]);

        return $user->refresh();
    }

    private function item(array $overrides = []): EquipmentItem
    {
        return EquipmentItem::create($overrides + ['name' => 'Gimbal', 'quantity' => 1]);
    }

    private function shoot(array $overrides = []): Shoot
    {
        return Shoot::create($overrides + [
            'title' => 'Tea montage',
            'starts_at' => Carbon::parse('2026-08-14 09:00'),
            'status' => Shoot::STATUS_PLANNED,
        ]);
    }

    private function addKit(Shoot $shoot, EquipmentItem $item, int $quantity = 1): ShootKit
    {
        return $shoot->kits()->create([
            'equipment_item_id' => $item->id,
            'quantity' => $quantity,
        ]);
    }

    // ——— The availability rule ———

    public function test_an_item_on_no_other_shoot_is_entirely_free(): void
    {
        $shoot = $this->shoot();

        $this->assertTrue(KitAvailability::during($shoot)->isEmpty());
    }

    public function test_a_shoot_does_not_clash_with_its_own_kit(): void
    {
        $shoot = $this->shoot();
        $this->addKit($shoot, $this->item());

        // Editing a shoot that reports its own gear as booked is the failure
        // that makes people stop trusting the screen.
        $this->assertTrue(KitAvailability::during($shoot)->isEmpty());
    }

    public function test_an_overlapping_shoot_commits_the_item(): void
    {
        $item = $this->item(['quantity' => 3]);
        $mine = $this->shoot();
        $theirs = $this->shoot(['title' => 'Other', 'starts_at' => Carbon::parse('2026-08-14 14:00')]);

        $this->addKit($theirs, $item, 2);

        $committed = KitAvailability::during($mine);

        $this->assertSame(2, (int) $committed[$item->id]->committed);
        $this->assertSame(2, (int) $committed[$item->id]->reserved);
        $this->assertSame(0, (int) $committed[$item->id]->out);
    }

    public function test_a_shoot_on_the_next_day_does_not_clash(): void
    {
        $item = $this->item();
        $mine = $this->shoot();
        $theirs = $this->shoot(['title' => 'Tomorrow', 'starts_at' => Carbon::parse('2026-08-15 09:00')]);

        $this->addKit($theirs, $item);

        // An undated finish means "the rest of that day", never open-ended --
        // otherwise one shoot would hold every camera forever.
        $this->assertTrue(KitAvailability::during($mine)->isEmpty());
    }

    public function test_windows_that_only_touch_do_not_clash(): void
    {
        $item = $this->item();
        $morning = $this->shoot([
            'starts_at' => Carbon::parse('2026-08-14 08:00'),
            'ends_at' => Carbon::parse('2026-08-14 13:00'),
        ]);
        $afternoon = $this->shoot([
            'title' => 'Afternoon',
            'starts_at' => Carbon::parse('2026-08-14 13:00'),
            'ends_at' => Carbon::parse('2026-08-14 18:00'),
        ]);

        $this->addKit($morning, $item);

        // Handing the camera over at 13:00 is a real thing crews do.
        $this->assertTrue(KitAvailability::during($afternoon)->isEmpty());
    }

    public function test_a_cancelled_shoot_holds_nothing(): void
    {
        $item = $this->item();
        $mine = $this->shoot();
        $theirs = $this->shoot(['title' => 'Dropped', 'status' => Shoot::STATUS_CANCELLED]);

        $this->addKit($theirs, $item);

        $this->assertTrue(KitAvailability::during($mine)->isEmpty());
    }

    public function test_returned_kit_stops_being_committed(): void
    {
        $item = $this->item();
        $mine = $this->shoot();
        $theirs = $this->shoot(['title' => 'Earlier today']);

        $line = $this->addKit($theirs, $item);
        $line->forceFill(['returned_at' => now(), 'returned_quantity' => 1])->save();

        $this->assertTrue(KitAvailability::during($mine)->isEmpty());
    }

    public function test_kit_that_never_came_back_reduces_the_stock_for_good(): void
    {
        $item = $this->item(['name' => 'Battery', 'quantity' => 12]);
        $past = $this->shoot(['starts_at' => Carbon::parse('2026-03-01 09:00')]);

        $line = $this->addKit($past, $item, 4);
        $line->forceFill(['returned_at' => now(), 'returned_quantity' => 2])->save();

        // Two of twelve are gone. Not "booked in March" -- gone.
        $this->assertSame(2, (int) KitAvailability::shortfalls()[$item->id]);
        $this->assertSame(2, $line->refresh()->shortfall());
    }

    // ——— Checking out and back in ———

    public function test_a_view_only_crew_member_can_tick_the_list(): void
    {
        $shoot = $this->shoot();
        $line = $this->addKit($shoot, $this->item());
        $user = $this->crew(['view']);

        $this->actingAs($user)
            ->postJson(route('shoots.kit.check-out', [$shoot, $line]))
            ->assertOk()
            ->assertJsonPath('checkedOut', true);

        $line->refresh();
        $this->assertNotNull($line->checked_out_at);
        $this->assertSame($user->id, $line->checked_out_by_id);
    }

    public function test_view_only_cannot_change_what_is_on_the_list(): void
    {
        $shoot = $this->shoot();
        $item = $this->item();

        // Ticking is the crew's. Deciding what goes is the producer's.
        $this->actingAs($this->crew(['view']))
            ->postJson(route('shoots.kit.store', $shoot), ['equipment_item_id' => $item->id])
            ->assertForbidden();

        $this->assertDatabaseCount('shoot_kit', 0);
    }

    public function test_checking_out_twice_keeps_the_first_timestamp(): void
    {
        $shoot = $this->shoot();
        $line = $this->addKit($shoot, $this->item());
        $user = $this->crew();

        $this->actingAs($user)->postJson(route('shoots.kit.check-out', [$shoot, $line]))->assertOk();
        $first = $line->refresh()->checked_out_at;

        // A double-tap on one bar of signal must not error or re-stamp.
        $this->actingAs($user)->postJson(route('shoots.kit.check-out', [$shoot, $line]))->assertOk();

        $this->assertEquals($first, $line->refresh()->checked_out_at);
    }

    public function test_the_signed_in_user_is_recorded_not_whoever_the_payload_names(): void
    {
        $shoot = $this->shoot();
        $line = $this->addKit($shoot, $this->item());
        $actor = $this->crew();
        $someoneElse = User::factory()->create();

        $this->actingAs($actor)->postJson(route('shoots.kit.check-in', [$shoot, $line]), [
            'returned_by_id' => $someoneElse->id,
            'checked_out_by_id' => $someoneElse->id,
        ])->assertOk();

        // The custody columns are not fillable, so this cannot be laundered.
        $this->assertSame($actor->id, $line->refresh()->returned_by_id);
    }

    public function test_a_partial_return_is_flagged_as_missing(): void
    {
        $shoot = $this->shoot();
        $line = $this->addKit($shoot, $this->item(['name' => 'Battery', 'quantity' => 12]), 4);

        $this->actingAs($this->crew())
            ->postJson(route('shoots.kit.check-in', [$shoot, $line]), ['returned_quantity' => 3])
            ->assertOk();

        $line->refresh();
        $this->assertSame(1, $line->shortfall());
        $this->assertSame(ShootKit::CONDITION_MISSING, $line->condition);
    }

    public function test_take_all_ticks_everything_not_yet_taken(): void
    {
        $shoot = $this->shoot();
        $a = $this->addKit($shoot, $this->item(['name' => 'Gimbal']));
        $b = $this->addKit($shoot, $this->item(['name' => 'Tripod']));

        $this->actingAs($this->crew())
            ->postJson(route('shoots.kit.bulk', $shoot), ['action' => 'check-out'])
            ->assertOk();

        $this->assertNotNull($a->refresh()->checked_out_at);
        $this->assertNotNull($b->refresh()->checked_out_at);
    }

    public function test_a_kit_line_from_another_shoot_is_not_found(): void
    {
        $mine = $this->shoot();
        $theirs = $this->shoot(['title' => 'Other']);
        $line = $this->addKit($theirs, $this->item());

        // Scoped bindings, so the controller never has to remember to check.
        $this->actingAs($this->crew())
            ->postJson(route('shoots.kit.check-out', [$mine, $line]))
            ->assertNotFound();
    }

    // ——— Guarding the history ———

    public function test_a_shoot_cannot_be_deleted_while_its_kit_is_out(): void
    {
        $shoot = $this->shoot();
        $line = $this->addKit($shoot, $this->item());
        $line->forceFill(['checked_out_at' => now()])->save();

        $this->actingAs($this->crew())
            ->delete(route('shoots.destroy', $shoot))
            ->assertRedirect();

        // The kit rows are the only record of who has the camera.
        $this->assertDatabaseHas('shoots', ['id' => $shoot->id]);
    }

    public function test_equipment_that_has_been_on_a_shoot_cannot_be_deleted(): void
    {
        $item = $this->item();
        $this->addKit($this->shoot(), $item);

        $user = $this->crew();
        $user->syncPermissions(['equipment' => ['view', 'delete'], 'shoots' => ['view']]);

        $this->actingAs($user->refresh())
            ->delete(route('equipment.destroy', $item))
            ->assertRedirect();

        $this->assertDatabaseHas('equipment_items', ['id' => $item->id]);
    }

    public function test_a_category_in_use_reports_its_usage(): void
    {
        $category = TaxonomyTerm::create([
            'type' => TaxonomyTerm::TYPE_EQUIPMENT_CATEGORY,
            'name' => 'Lens',
            'slug' => 'lens',
        ]);

        $this->item(['name' => '24-70', 'category_id' => $category->id]);

        // Not counted, deleting "Lens" would report zero uses and null every
        // lens the studio owns.
        $this->assertSame(1, $category->usageCount());
    }

    // ——— Authorization ———

    public function test_a_permission_less_employee_is_refused_both_modules(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($employee)->get(route('shoots.index'))->assertForbidden();
        $this->actingAs($employee)->get(route('equipment.index'))->assertForbidden();
    }

    public function test_shoots_access_does_not_leak_into_equipment(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $user->syncPermissions(['shoots' => ['view', 'edit']]);

        $this->actingAs($user->refresh())->get(route('shoots.index'))->assertOk();
        $this->actingAs($user)->get(route('equipment.index'))->assertForbidden();
    }

    /** @dataProvider \Tests\Feature\EmployeeAccessTest::adminRoutes */
    public function test_a_granted_crew_member_is_still_refused_every_admin_area(string $url): void
    {
        $this->actingAs($this->crew())->get($url)->assertForbidden();
    }
}
