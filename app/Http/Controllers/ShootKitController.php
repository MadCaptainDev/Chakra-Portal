<?php

namespace App\Http\Controllers;

use App\Models\EquipmentItem;
use App\Models\Shoot;
use App\Models\ShootKit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The checklist endpoints.
 *
 * Adding and removing kit is planning work and needs shoots.edit. Ticking it
 * off needs only shoots.view -- the crew were explicit that they tick their own
 * list. That is the right boundary anyway: the producer decides what goes, the
 * crew record what actually went.
 *
 * Every action here is idempotent. Someone standing at the studio door on one
 * bar of signal will double-tap, and a second tap must never produce an error
 * dialog -- it should simply report the state that already holds.
 */
class ShootKitController extends Controller
{
    public function store(Request $request, Shoot $shoot): JsonResponse
    {
        $validated = $request->validate([
            'equipment_item_id' => ['required', Rule::exists('equipment_items', 'id')],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);

        // Adding the same item twice is a quantity, not a second row -- and the
        // unique index would refuse it anyway.
        $line = $shoot->kits()->firstOrNew(['equipment_item_id' => $validated['equipment_item_id']]);
        $line->quantity = $validated['quantity'] ?? 1;
        $line->save();

        return response()->json($this->payload($line->fresh(['item', 'checkedOutBy'])), 201);
    }

    public function destroy(Shoot $shoot, ShootKit $kit): JsonResponse
    {
        // Once it is out, removing the line would erase the record of who took
        // it. Check it back in first.
        if ($kit->isCheckedOut()) {
            return response()->json([
                'message' => 'That is already out. Check it back in before removing it from the list.',
            ], 422);
        }

        $kit->delete();

        return response()->json(['deleted' => true]);
    }

    /** Tick it: this is going in the van. */
    public function checkOut(Request $request, Shoot $shoot, ShootKit $kit): JsonResponse
    {
        if ($kit->checked_out_at === null) {
            $kit->forceFill([
                'checked_out_at' => now(),
                'checked_out_by_id' => $request->user()->id,
                // Taking it out again after a return re-opens the line.
                'returned_at' => null,
                'returned_by_id' => null,
                'returned_quantity' => null,
            ])->save();
        }

        return response()->json($this->payload($kit->fresh(['item', 'checkedOutBy'])));
    }

    /** Untick: it never actually went. */
    public function undoCheckOut(Shoot $shoot, ShootKit $kit): JsonResponse
    {
        $kit->forceFill([
            'checked_out_at' => null,
            'checked_out_by_id' => null,
        ])->save();

        return response()->json($this->payload($kit->fresh(['item', 'checkedOutBy'])));
    }

    /**
     * It came back — all of it, or some of it.
     *
     * A shortfall is recorded rather than argued with: the quantity that
     * returned is stored, and the difference goes on counting against the
     * studio's stock until somebody resolves it.
     */
    public function checkIn(Request $request, Shoot $shoot, ShootKit $kit): JsonResponse
    {
        $validated = $request->validate([
            'returned_quantity' => ['nullable', 'integer', 'min:0', 'max:'.$kit->quantity],
            'condition' => ['nullable', Rule::in(array_keys(ShootKit::CONDITIONS))],
            'condition_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $returned = $validated['returned_quantity'] ?? $kit->quantity;

        $kit->forceFill([
            'returned_at' => now(),
            'returned_by_id' => $request->user()->id,
            'returned_quantity' => $returned,
            // A partial return is a missing item whether or not anyone says so.
            'condition' => $validated['condition']
                ?? ($returned < $kit->quantity ? ShootKit::CONDITION_MISSING : null),
            'condition_note' => $validated['condition_note'] ?? $kit->condition_note,
        ])->save();

        return response()->json($this->payload($kit->fresh(['item', 'checkedOutBy'])));
    }

    /**
     * The button that gets used: everything into the van, or everything back.
     *
     * Bulk return never records damage -- flagging something as broken should
     * take naming it.
     */
    public function bulk(Request $request, Shoot $shoot): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['check-out', 'check-in'])],
        ]);

        $user = $request->user();

        if ($validated['action'] === 'check-out') {
            $shoot->kits()->whereNull('checked_out_at')->get()
                ->each(fn (ShootKit $line) => $line->forceFill([
                    'checked_out_at' => now(),
                    'checked_out_by_id' => $user->id,
                ])->save());
        } else {
            $shoot->kits()->whereNotNull('checked_out_at')->whereNull('returned_at')->get()
                ->each(fn (ShootKit $line) => $line->forceFill([
                    'returned_at' => now(),
                    'returned_by_id' => $user->id,
                    'returned_quantity' => $line->quantity,
                ])->save());
        }

        return response()->json([
            'kit' => $shoot->kits()->with(['item', 'checkedOutBy'])->get()
                ->map(fn (ShootKit $line) => $this->payload($line))->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ShootKit $kit): array
    {
        return [
            'id' => $kit->id,
            'name' => $kit->item?->name,
            'quantity' => $kit->quantity,
            'checkedOut' => $kit->checked_out_at !== null,
            'returned' => $kit->returned_at !== null,
            'checkedOutBy' => $kit->checkedOutBy?->name,
            'checkedOutAt' => $kit->checked_out_at?->format('H:i'),
            'condition' => $kit->conditionLabel(),
            'shortfall' => $kit->shortfall(),
        ];
    }
}
