<?php

namespace App\Support;

use App\Models\EquipmentItem;
use App\Models\Shoot;
use App\Models\ShootKit;
use Illuminate\Support\Facades\DB;

/**
 * "What goes with it": adding the Sony FX30 to a shoot brings its power zoom
 * lens and a 256GB card along, without anyone remembering each one.
 *
 * Done on the server so it holds wherever kit is added from -- the
 * producer's picker, the crew's runner, a tool. The browser picker shows the
 * same thing as it happens, but it is this that makes it true.
 */
class KitBundle
{
    /**
     * Add items to a shoot's kit, with everything that travels with them.
     *
     * A line that is already on the list keeps what it has: an asked-for
     * quantity replaces it, a bundled one never lowers it. Retired items are
     * not pulled in by a bundle.
     *
     * @param  array<int, int>  $wanted  equipment item id => quantity
     * @return int how many lines were newly added
     */
    public static function addTo(Shoot $shoot, array $wanted): int
    {
        $added = 0;

        DB::transaction(function () use ($shoot, $wanted, &$added) {
            foreach (self::expand($wanted) as $itemId => [$quantity, $asked]) {
                $line = $shoot->kits()->firstOrNew(['equipment_item_id' => $itemId]);

                if (! $line->exists) {
                    $line->quantity = $quantity;
                    $added++;
                } elseif ($asked) {
                    $line->quantity = $quantity;
                } else {
                    $line->quantity = max($line->quantity, $quantity);
                }

                $line->save();
            }
        });

        return $added;
    }

    /**
     * The asked-for items plus their bundles, walked to any depth (a camera's
     * cage has its own screws) and never in a circle.
     *
     * @param  array<int, int>  $wanted
     * @return array<int, array{0: int, 1: bool}> item id => [quantity, asked for by name]
     */
    public static function expand(array $wanted): array
    {
        $out = [];
        foreach ($wanted as $id => $quantity) {
            $out[(int) $id] = [max(1, (int) $quantity), true];
        }

        $children = DB::table('equipment_bundle_items')
            ->join('equipment_items', 'equipment_items.id', '=', 'equipment_bundle_items.child_item_id')
            ->where('equipment_items.is_active', true)
            ->get(['parent_item_id', 'child_item_id', 'equipment_bundle_items.quantity'])
            ->groupBy('parent_item_id');

        $queue = array_keys($out);
        $seen = [];

        while ($queue) {
            $parent = array_shift($queue);
            if (isset($seen[$parent])) {
                continue;
            }
            $seen[$parent] = true;

            foreach ($children[$parent] ?? [] as $row) {
                $child = (int) $row->child_item_id;
                $need = (int) $row->quantity * $out[$parent][0];

                if (! isset($out[$child])) {
                    $out[$child] = [$need, false];
                } elseif (! $out[$child][1]) {
                    $out[$child][0] = max($out[$child][0], $need);
                }
                $queue[] = $child;
            }
        }

        return $out;
    }
}
