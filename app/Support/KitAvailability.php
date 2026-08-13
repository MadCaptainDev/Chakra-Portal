<?php

namespace App\Support;

use App\Models\Shoot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * How much of each item is free during a shoot's window.
 *
 * The one query the kit picker rests on. Deliberately answered for every item
 * at once rather than per row: a register of forty items must not become forty
 * queries behind one screen.
 *
 * Two states worth separating, because they need different words on screen:
 *
 *   RESERVED -- promised to another shoot whose window overlaps this one. Still
 *               in the cupboard, but somebody is expecting it.
 *   OUT      -- checked out and not yet returned. Physically gone.
 *
 * Both reduce what is free. Neither blocks anything: the crew asked for a
 * warning, not a gate, and a hard block at 6am is a thing people route around.
 */
class KitAvailability
{
    /**
     * Committed quantity per equipment item during a shoot's window, keyed by
     * equipment_item_id.
     *
     * The shoot's own rows are excluded -- an item does not clash with itself.
     *
     * @return Collection<int, object{committed: int, out: int, reserved: int}>
     */
    public static function during(Shoot $shoot): Collection
    {
        [$start, $end] = self::window($shoot);

        return DB::table('shoot_kit')
            ->join('shoots', 'shoots.id', '=', 'shoot_kit.shoot_id')
            ->whereNull('shoot_kit.returned_at')
            ->where('shoot_kit.shoot_id', '!=', $shoot->id)
            // A cancelled shoot is not holding anything.
            ->where('shoots.status', '!=', Shoot::STATUS_CANCELLED)
            ->where(fn ($query) => self::overlaps($query, $start, $end))
            ->groupBy('shoot_kit.equipment_item_id')
            ->selectRaw('shoot_kit.equipment_item_id as item_id')
            ->selectRaw('SUM(shoot_kit.quantity) as committed')
            ->selectRaw('SUM(CASE WHEN shoot_kit.checked_out_at IS NOT NULL THEN shoot_kit.quantity ELSE 0 END) as out')
            ->selectRaw('SUM(CASE WHEN shoot_kit.checked_out_at IS NULL THEN shoot_kit.quantity ELSE 0 END) as reserved')
            ->get()
            ->keyBy('item_id');
    }

    /**
     * How many of each item never came back, across every shoot ever.
     *
     * A shortfall is not a booking -- it is stock the studio no longer has. Two
     * of twelve batteries lost in March means eleven... ten... are available in
     * August, and for every window, not just the one they went missing on. So
     * it reduces the effective quantity rather than joining the window query.
     *
     * @return Collection<int, int> equipment_item_id => quantity still missing
     */
    public static function shortfalls(): Collection
    {
        return DB::table('shoot_kit')
            ->whereNotNull('returned_at')
            ->whereRaw('COALESCE(returned_quantity, quantity) < quantity')
            ->groupBy('equipment_item_id')
            ->selectRaw('equipment_item_id as item_id')
            ->selectRaw('SUM(quantity - COALESCE(returned_quantity, quantity)) as missing')
            ->pluck('missing', 'item_id');
    }

    /**
     * The shoots competing for a given item during this window, so the warning
     * can name them rather than saying "unavailable".
     *
     * @return Collection<int, Shoot>
     */
    public static function clashesFor(Shoot $shoot, int $equipmentItemId): Collection
    {
        [$start, $end] = self::window($shoot);

        return Shoot::query()
            ->whereKeyNot($shoot->id)
            ->where('status', '!=', Shoot::STATUS_CANCELLED)
            ->whereHas('kits', fn ($query) => $query
                ->where('equipment_item_id', $equipmentItemId)
                ->whereNull('returned_at'))
            ->where(fn ($query) => self::overlaps($query, $start, $end))
            ->with('client')
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * A shoot's window.
     *
     * A missing end time means "some time that day" rather than "forever". Left
     * open-ended, one undated shoot would hold every camera in the building
     * until somebody remembered to close it.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function window(Shoot $shoot): array
    {
        $start = $shoot->starts_at instanceof Carbon
            ? $shoot->starts_at->copy()
            : Carbon::parse($shoot->starts_at);

        $end = $shoot->ends_at
            ? ($shoot->ends_at instanceof Carbon ? $shoot->ends_at->copy() : Carbon::parse($shoot->ends_at))
            : $start->copy()->endOfDay();

        // A finish before the start is a typo, not a zero-length booking.
        return $end->lessThan($start) ? [$start, $start->copy()->endOfDay()] : [$start, $end];
    }

    /**
     * Half-open overlap: two windows touching end-to-start do not clash, so a
     * morning shoot ending at 13:00 and an afternoon one starting at 13:00 can
     * share a camera.
     *
     * Written entirely in bound parameters rather than as
     * COALESCE(ends_at, <end of that row's day>), because computing the end of
     * a row's own day needs date functions that differ between MySQL and
     * SQLite -- and this app tests on SQLite and runs on MySQL. A divergence
     * here would mean the availability rule behaves one way in the suite and
     * another in the studio.
     *
     * The trick is that for a row with no end time, "its day ends after my
     * window starts" is the same statement as "it starts on or after the first
     * moment of my start's day" -- which needs no SQL functions at all.
     */
    private static function overlaps($query, Carbon $start, Carbon $end): void
    {
        $query->where('shoots.starts_at', '<', $end)
            ->where(function ($inner) use ($start) {
                $inner
                    ->where(fn ($dated) => $dated
                        ->whereNotNull('shoots.ends_at')
                        ->where('shoots.ends_at', '>', $start))
                    ->orWhere(fn ($undated) => $undated
                        ->whereNull('shoots.ends_at')
                        ->where('shoots.starts_at', '>=', $start->copy()->startOfDay()));
            });
    }
}
