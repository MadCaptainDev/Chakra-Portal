<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\EquipmentItem;
use App\Models\Shoot;
use App\Models\ShootCrew;
use App\Models\User;
use App\Services\KitCustody;
use App\Support\KitBundle;
use App\Support\KitSuggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Kit pictures, "what goes with it", and the usual kit for a client. */
class KitBundleTest extends TestCase
{
    use RefreshDatabase;

    private function item(string $name, int $quantity = 1, bool $active = true): EquipmentItem
    {
        return EquipmentItem::create(['name' => $name, 'quantity' => $quantity, 'is_active' => $active, 'status' => EquipmentItem::STATUS_AVAILABLE]);
    }

    private function shoot(array $attributes = []): Shoot
    {
        return Shoot::create(array_merge(['title' => 'Shoot', 'starts_at' => now()->addDay(), 'status' => Shoot::STATUS_CONFIRMED], $attributes));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_adding_a_camera_brings_what_goes_with_it(): void
    {
        $camera = $this->item('Sony FX30');
        $lens = $this->item('Power zoom lens');
        $card = $this->item('256GB memory card', 4);
        $camera->bundle()->sync([$lens->id => ['quantity' => 1], $card->id => ['quantity' => 2]]);
        $shoot = $this->shoot();

        $this->actingAs($this->admin())->post(route('shoots.kit.store-many', $shoot), [
            'items' => [['equipment_item_id' => $camera->id, 'quantity' => 1]],
        ])->assertSessionHas('status', 'Added 3 items to the kit.');

        $this->assertEquals(
            [$camera->id => 1, $lens->id => 1, $card->id => 2],
            $shoot->kits()->pluck('quantity', 'equipment_item_id')->all(),
        );
    }

    public function test_a_bundle_is_followed_to_any_depth_without_going_in_circles(): void
    {
        $camera = $this->item('Camera');
        $cage = $this->item('Cage');
        $screws = $this->item('Cage screws', 10);
        $retired = $this->item('Old monitor', 1, active: false);
        $camera->bundle()->sync([$cage->id => ['quantity' => 1], $retired->id => ['quantity' => 1]]);
        $cage->bundle()->sync([$screws->id => ['quantity' => 4], $camera->id => ['quantity' => 1]]);

        $expanded = KitBundle::expand([$camera->id => 2]);

        $this->assertEquals([2, true], $expanded[$camera->id]);
        $this->assertEquals([2, false], $expanded[$cage->id]);
        $this->assertEquals([8, false], $expanded[$screws->id]);
        $this->assertArrayNotHasKey($retired->id, $expanded);
    }

    public function test_a_bundle_never_lowers_a_quantity_already_on_the_list(): void
    {
        $camera = $this->item('Camera');
        $battery = $this->item('Battery', 6);
        $camera->bundle()->sync([$battery->id => ['quantity' => 2]]);
        $shoot = $this->shoot();
        $shoot->kits()->create(['equipment_item_id' => $battery->id, 'quantity' => 5]);

        KitBundle::addTo($shoot, [$camera->id => 1]);

        $this->assertSame(5, $shoot->kits()->where('equipment_item_id', $battery->id)->value('quantity'));
    }

    public function test_the_crew_adding_from_the_runner_get_the_bundle_too(): void
    {
        $camera = $this->item('Sony FX30');
        $lens = $this->item('Power zoom lens');
        $camera->bundle()->sync([$lens->id => ['quantity' => 1]]);
        $shoot = $this->shoot();
        $crew = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        ShootCrew::create(['shoot_id' => $shoot->id, 'user_id' => $crew->id, 'role' => 'Camera']);

        $this->actingAs($crew)->get(route('my.shoots.run', $shoot))->assertSee('+ Power zoom lens');
        $this->actingAs($crew)->post(route('my.shoots.kit.add', $shoot), ['items' => [$camera->id]])->assertRedirect();

        $this->assertEqualsCanonicalizing([$camera->id, $lens->id], $shoot->kits()->pluck('equipment_item_id')->all());
    }

    public function test_the_register_saves_a_photo_and_what_goes_with_an_item(): void
    {
        $lens = $this->item('Power zoom lens');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('equipment.store'), [
            'name' => 'Sony FX30', 'quantity' => 1, 'bundle_sent' => 1,
            'image' => UploadedFile::fake()->image('fx30.jpg', 600, 400),
            'bundle' => [['item_id' => $lens->id, 'quantity' => 1]],
        ])->assertRedirect(route('equipment.index'));

        $camera = EquipmentItem::where('name', 'Sony FX30')->firstOrFail();
        $this->assertStringStartsWith('uploads/equipment/', $camera->image_path);
        $this->assertFileExists(public_path($camera->image_path));
        $this->assertSame([$lens->id], $camera->bundle->pluck('id')->all());

        $this->actingAs($admin)->get(route('equipment.index'))->assertOk()->assertSee('Brings Power zoom lens');

        // Removing both again.
        $path = $camera->image_path;
        $this->actingAs($admin)->put(route('equipment.update', $camera), [
            'name' => 'Sony FX30', 'quantity' => 1, 'bundle_sent' => 1, 'remove_image' => 1,
        ])->assertRedirect();

        $this->assertNull($camera->fresh()->image_path);
        $this->assertFileDoesNotExist(public_path($path));
        $this->assertCount(0, $camera->fresh()->bundle);
    }

    public function test_an_item_cannot_go_with_itself(): void
    {
        $camera = $this->item('Camera');

        $this->actingAs($this->admin())->put(route('equipment.update', $camera), [
            'name' => 'Camera', 'quantity' => 1, 'bundle_sent' => 1,
            'bundle' => [['item_id' => $camera->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('bundle.0.item_id');
    }

    public function test_the_usual_kit_is_what_the_clients_recent_shoots_actually_took(): void
    {
        $client = Client::factory()->create();
        $taker = User::factory()->create();
        $camera = $this->item('Camera');
        $light = $this->item('LC Light');
        $gimbal = $this->item('Gimbal');
        $planned = $this->item('Backdrop');

        // Three past shoots: camera every time, light twice, gimbal once, backdrop listed but never taken.
        foreach ([[$camera, $light, $gimbal], [$camera, $light], [$camera]] as $i => $took) {
            $past = $this->shoot(['client_id' => $client->id, 'starts_at' => now()->subDays($i + 1), 'status' => Shoot::STATUS_COMPLETED]);
            foreach ($took as $item) {
                $past->kits()->create(['equipment_item_id' => $item->id, 'quantity' => 1]);
            }
            KitCustody::pickUpAll($past, $taker);
            $past->kits()->create(['equipment_item_id' => $planned->id, 'quantity' => 1]);
        }
        // Another client's shoot does not count.
        $other = $this->shoot(['client_id' => Client::factory()->create()->id, 'starts_at' => now()->subDay()]);
        $other->kits()->create(['equipment_item_id' => $gimbal->id, 'quantity' => 1]);
        KitCustody::pickUpAll($other, $taker);

        $shoot = $this->shoot(['client_id' => $client->id]);
        $usual = KitSuggestion::forShoot($shoot);

        $this->assertSame(['Camera', 'LC Light'], $usual->map(fn ($row) => $row['item']->name)->all());

        $this->actingAs($this->admin())->get(route('shoots.show', $shoot))->assertSee('Add usual kit for');
        $this->actingAs($this->admin())->post(route('shoots.kit.usual', $shoot))
            ->assertSessionHas('status', 'Added the usual kit: 2 items.');

        $this->assertEqualsCanonicalizing([$camera->id, $light->id], $shoot->kits()->pluck('equipment_item_id')->all());
        $this->assertCount(0, KitSuggestion::forShoot($shoot->fresh()));
    }
}
