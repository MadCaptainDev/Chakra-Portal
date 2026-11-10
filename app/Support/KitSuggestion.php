<?php

namespace App\Support;

use App\Models\EquipmentItem;
use App\Models\Shoot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "The usual kit for this client": what actually went on their last few
 * shoots, most-used first.
 *
 * Read from shoot_kit lines that were picked up -- what was planned and then
 * left on the shelf says nothing about what the client's shoots need.
 */
class KitSuggestion
{
    /** How many of the client's past shoots to learn from. */
    public const SHOOTS = 5;

    /**
     * @return Collection<int, array{item: EquipmentItem, quantity: int, times: int, of: int}>
     */
    public static function forShoot(Shoot $shoot): Collection
    {
        if (! $shoot->client_id) {
            return collect();
        }

        $past = Shoot::query()
            ->where('client_id', $shoot->client_id)
            ->whereKeyNot($shoot->id)
            ->where('status', '!=', Shoot::STATUS_CANCELLED)
            ->whereHas('kits', fn ($k) => $k->whereNotNull('checked_out_at'))
            ->orderByDesc('starts_at')
            ->limit(self::SHOOTS)
            ->pluck('id');

        if ($past->isEmpty()) {
            return collect();
        }

        $used = DB::table('shoot_kit')
            ->whereIn('shoot_id', $past)
            ->whereNotNull('checked_out_at')
            ->selectRaw('equipment_item_id, COUNT(*) AS times, MAX(quantity) AS quantity')
            ->groupBy('equipment_item_id')
            ->get()
            ->keyBy('equipment_item_id');

        $already = $shoot->kits()->pluck('equipment_item_id')->all();

        return EquipmentItem::query()->active()
            ->whereIn('id', $used->keys())
            ->whereNotIn('id', $already)
            ->get()
            ->map(fn (EquipmentItem $item) => [
                'item' => $item,
                'quantity' => (int) $used[$item->id]->quantity,
                'times' => (int) $used[$item->id]->times,
                'of' => $past->count(),
            ])
            // Something that went once in five shoots is not "usual".
            ->filter(fn (array $row) => $row['times'] * 2 >= $row['of'])
            ->sortByDesc('times')
            ->values();
    }
}
