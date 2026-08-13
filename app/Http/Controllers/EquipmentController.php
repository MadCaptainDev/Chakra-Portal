<?php

namespace App\Http\Controllers;

use App\Models\EquipmentItem;
use App\Models\TaxonomyTerm;
use App\Support\KitAvailability;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The kit register: what the studio owns.
 */
class EquipmentController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim($request->string('q')->toString());
        $showRetired = $request->boolean('retired');

        $items = EquipmentItem::query()
            ->with('category')
            ->when(! $showRetired, fn ($query) => $query->active())
            ->when($search !== '', fn ($query) => $query->where(
                fn ($inner) => $inner
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('identifier', 'like', "%{$search}%")
            ))
            ->ordered()
            ->get();

        /*
         * What is out right now, so the register can say "with Aron" rather
         * than only listing what exists. Shortfalls are folded in as well --
         * a battery that never came back is not stock.
         */
        $shortfalls = KitAvailability::shortfalls();

        return view('equipment.index', [
            'groups' => $items->groupBy(fn (EquipmentItem $item) => $item->categoryLabel()),
            'shortfalls' => $shortfalls,
            'filters' => ['q' => $search, 'retired' => $showRetired],
            'categories' => TaxonomyTerm::options(TaxonomyTerm::TYPE_EQUIPMENT_CATEGORY),
            'total' => $items->sum('quantity'),
            'missing' => $shortfalls->sum(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $item = EquipmentItem::create($this->validated($request));

        return redirect()
            ->route('equipment.index')
            ->with('status', $item->name.' added to the register.');
    }

    public function update(Request $request, EquipmentItem $equipment): RedirectResponse
    {
        $equipment->update($this->validated($request, $equipment));

        return redirect()->route('equipment.index')->with('status', 'Item updated.');
    }

    /**
     * Removing a piece of kit is refused once it has been on a shoot.
     *
     * The rows in shoot_kit are the only record of who took what and whether it
     * came back; deleting the item cascades them away and the history of those
     * shoots quietly becomes untrue. Retiring keeps the record and takes the
     * item out of every picker, which is what "we sold that camera" actually
     * means.
     */
    public function destroy(EquipmentItem $equipment): RedirectResponse
    {
        if ($equipment->kits()->exists()) {
            return redirect()
                ->route('equipment.index')
                ->with('error', $equipment->name.' has been out on a shoot, so it cannot be deleted. Mark it retired instead — it will disappear from the pickers and keep its history.');
        }

        $equipment->delete();

        return redirect()->route('equipment.index')->with('status', 'Item removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?EquipmentItem $item = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'category_id' => [
                'nullable',
                Rule::exists('taxonomy_terms', 'id')->where('type', TaxonomyTerm::TYPE_EQUIPMENT_CATEGORY),
            ],
            'identifier' => ['nullable', 'string', 'max:120'],
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
