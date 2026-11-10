<?php

namespace App\Http\Controllers;

use App\Models\EquipmentItem;
use App\Models\Shoot;
use App\Models\ShootKit;
use App\Services\KitCustody;
use App\Support\KitBundle;
use App\Support\KitSuggestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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
        KitBundle::addTo($shoot, [(int) $validated['equipment_item_id'] => (int) ($validated['quantity'] ?? 1)]);
        $line = $shoot->kits()->where('equipment_item_id', $validated['equipment_item_id'])->firstOrFail();

        return response()->json($this->payload($line->fresh(['item', 'checkedOutBy'])), 201);
    }

    /**
     * The picker's "Add N items" button: everything staged in the modal,
     * saved in one trip instead of one form submit per item. Same
     * add-or-raise-the-quantity rule as store() above, just looped.
     */
    public function storeMany(Request $request, Shoot $shoot): RedirectResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.equipment_item_id' => ['required', Rule::exists('equipment_items', 'id')],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);

        // Each item brings what goes with it -- see KitBundle.
        $before = $shoot->kits()->count();
        KitBundle::addTo($shoot, collect($validated['items'])
            ->mapWithKeys(fn (array $row) => [(int) $row['equipment_item_id'] => (int) ($row['quantity'] ?? 1)])->all());
        $count = max(count($validated['items']), $shoot->kits()->count() - $before);

        return back()->with('status', $count === 1 ? 'Added 1 item to the kit.' : "Added {$count} items to the kit.");
    }

    /** One tap: everything this client's shoots usually take. */
    public function storeUsual(Shoot $shoot): RedirectResponse
    {
        $usual = KitSuggestion::forShoot($shoot);
        $added = KitBundle::addTo($shoot, $usual->mapWithKeys(fn (array $row) => [$row['item']->id => $row['quantity']])->all());

        return back()->with('status', $added === 0 ? 'Nothing to add.' : "Added the usual kit: {$added} ".str('item')->plural($added).'.');
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
        KitCustody::pickUp($kit, $request->user());

        return response()->json($this->payload($kit->fresh(['item', 'checkedOutBy'])));
    }

    /** Untick: it never actually went. */
    public function undoCheckOut(Shoot $shoot, ShootKit $kit): JsonResponse
    {
        KitCustody::putBack($kit);

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

        KitCustody::giveBack(
            $kit, $request->user(),
            $validated['returned_quantity'] ?? null,
            $validated['condition'] ?? null,
            $validated['condition_note'] ?? null,
        );

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

        $validated['action'] === 'check-out'
            ? KitCustody::pickUpAll($shoot, $user)
            : KitCustody::giveBackAll($shoot, $user);

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
