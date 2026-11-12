<?php

namespace Tests\Feature;

use App\Models\EquipmentItem;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The printable call sheet opens, with its kit. */
class CallSheetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_call_sheet_opens_with_the_kit(): void
    {
        $this->withoutVite();
        $shoot = Shoot::create(['title' => 'Janet reels', 'starts_at' => now()->addDay(), 'status' => Shoot::STATUS_CONFIRMED]);
        $item = EquipmentItem::create(['name' => 'Sony FX30', 'quantity' => 1, 'is_active' => true, 'status' => 'available']);
        $shoot->kits()->create(['equipment_item_id' => $item->id, 'quantity' => 1]);

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->get(route('shoots.call-sheet', $shoot))->assertOk()->assertSee('Sony FX30');
    }
}
