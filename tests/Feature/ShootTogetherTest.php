<?php

namespace Tests\Feature;

use App\Models\EquipmentItem;
use App\Models\Shoot;
use App\Models\ShootCrew;
use App\Models\ShootEvent;
use App\Models\User;
use App\Services\KitCustody;
use App\Services\KitNotReady;
use App\Services\ShootRun;
use App\Support\KitAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * Shooting together: SVA Mart and SVA Silks, same place, same time. One trip,
 * one kit list, both running at once, and "Now shooting" splits the hours.
 */
class ShootTogetherTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $crew;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shoots.require_kit' => true]);
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->crew = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
    }

    private function shoot(string $title, array $extra = []): Shoot
    {
        $shoot = Shoot::create($extra + ['title' => $title, 'starts_at' => today()->setTime(11, 0), 'status' => Shoot::STATUS_CONFIRMED, 'location' => 'SVA, T Nagar']);
        ShootCrew::create(['shoot_id' => $shoot->id, 'user_id' => $this->crew->id, 'role' => 'Camera']);

        return $shoot;
    }

    /** @return array{0: Shoot, 1: Shoot} linked, the kit on Mart only and picked up */
    private function pair(): array
    {
        $mart = $this->shoot('SVA Mart');
        $silks = $this->shoot('SVA Silks');
        $this->actingAs($this->admin)->post(route('shoots.together', $mart), ['partner_id' => $silks->id])->assertSessionHas('status');
        $item = EquipmentItem::create(['name' => 'Sony FX30', 'quantity' => 1, 'is_active' => true, 'status' => EquipmentItem::STATUS_AVAILABLE]);
        $mart->kits()->create(['equipment_item_id' => $item->id, 'quantity' => 1]);
        KitCustody::pickUpAll($mart->fresh(), $this->crew);

        return [$mart->fresh(), $silks->fresh()];
    }

    public function test_only_shoots_on_the_same_day_are_linked(): void
    {
        $a = $this->shoot('SVA Mart');
        $b = $this->shoot('Janet', ['starts_at' => today()->addDay()->setTime(10, 0)]);

        $this->actingAs($this->admin)->post(route('shoots.together', $a), ['partner_id' => $b->id])->assertSessionHas('error');
        $this->assertNull($a->fresh()->together_key);
    }

    public function test_one_trip_and_one_kit_list_for_both_and_both_run_at_once(): void
    {
        [$mart, $silks] = $this->pair();
        $this->assertSame($mart->together_key, $silks->together_key);

        // Silks has no kit of its own: Mart's list covers it.
        ShootRun::depart($silks, $this->crew);
        $this->assertTrue($mart->fresh()->hasDeparted());
        ShootRun::reached($mart, $this->crew);
        $this->assertTrue($silks->fresh()->hasReached());

        ShootRun::start($mart->fresh(), $this->crew);
        ShootRun::start($silks->fresh(), $this->crew); // no "you are still on SVA Mart"
        $this->assertTrue($mart->fresh()->isInProgress());
        $this->assertTrue($silks->fresh()->isInProgress());

        // The next shoot of the day is never the partner.
        $this->assertNull(ShootRun::nextFor($mart->fresh(), $this->crew));
    }

    public function test_a_shoot_that_is_not_linked_still_waits_for_the_running_one(): void
    {
        [$mart] = $this->pair();
        $other = $this->shoot('Janet');
        $other->kits()->create(['equipment_item_id' => EquipmentItem::first()->id, 'quantity' => 1]);
        KitCustody::pickUpAll($other->fresh(), $this->crew);

        ShootRun::start($mart, $this->crew);

        $this->expectException(RuntimeException::class);
        ShootRun::start($other->fresh(), $this->crew);
    }

    public function test_now_shooting_switches_brand_and_splits_the_hours(): void
    {
        [$mart, $silks] = $this->pair();
        Carbon::setTestNow(today()->setTime(11, 0));
        ShootRun::start($mart, $this->crew);

        Carbon::setTestNow(today()->setTime(12, 40));
        $this->actingAs($this->crew)->post(route('my.shoots.focus', $silks))->assertRedirect(route('my.shoots.run', $silks));
        $this->assertTrue($silks->fresh()->isInProgress()); // the switch started it

        Carbon::setTestNow(today()->setTime(13, 30));
        ShootRun::focus($mart->fresh(), $this->crew);
        Carbon::setTestNow(today()->setTime(14, 0));
        ShootRun::finish($mart->fresh(), $this->crew);
        ShootRun::finish($silks->fresh(), $this->crew);

        $split = $mart->fresh()->focusMinutes();
        $this->assertSame(100 + 30, $split[$mart->id]); // 11:00–12:40 and 13:30–14:00
        $this->assertSame(50, $split[$silks->id]);      // 12:40–13:30
        $this->assertSame(1, ShootEvent::where('type', ShootEvent::FOCUS)->count());
        Carbon::setTestNow();
    }

    public function test_the_same_camera_on_both_linked_shoots_is_not_a_clash(): void
    {
        [$mart, $silks] = $this->pair();
        $camera = EquipmentItem::first();
        $silks->kits()->create(['equipment_item_id' => $camera->id, 'quantity' => 1]);

        $this->assertFalse(KitAvailability::during($silks->fresh())->has($camera->id));
        $this->assertTrue(KitAvailability::clashesFor($silks->fresh(), $camera->id)->isEmpty());
    }

    public function test_no_kit_anywhere_in_the_group_still_stops_the_trip(): void
    {
        $mart = $this->shoot('SVA Mart');
        $silks = $this->shoot('SVA Silks');
        $this->actingAs($this->admin)->post(route('shoots.together', $mart), ['partner_id' => $silks->id]);

        $this->expectException(KitNotReady::class);
        ShootRun::depart($silks->fresh(), $this->crew);
    }

    public function test_the_pages_show_the_link_and_the_switch(): void
    {
        [$mart, $silks] = $this->pair();
        $this->withoutVite();

        $this->actingAs($this->admin)->get(route('shoots.show', $mart))->assertOk()->assertSee('Shooting together')->assertSee('SVA Silks');
        ShootRun::reached($mart, $this->crew);
        $this->actingAs($this->crew)->get(route('my.shoots.run', $silks))->assertOk()->assertSee('Now shooting')->assertSee('one list for both');

        $this->actingAs($this->admin)->delete(route('shoots.untogether', $silks))->assertSessionHas('status');
        $this->assertNull($mart->fresh()->together_key); // a group of one is no group
    }
}
