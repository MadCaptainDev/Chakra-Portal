<?php

namespace App\Services;

use App\Models\Shoot;
use App\Models\ShootEvent;
use App\Models\ShootKit;
use App\Models\User;

/**
 * Who has the kit: picking it up and bringing it back.
 *
 * One place, because two screens do this -- the producer's shoot page and
 * the crew's own runner -- and the shoot's timeline has to hear about it
 * from both. Everything is safe to repeat: a second tap at the studio door
 * reports the state that already holds.
 */
class KitCustody
{
    public static function pickUp(ShootKit $line, User $by): void
    {
        if ($line->checked_out_at !== null) {
            return;
        }

        $line->forceFill([
            'checked_out_at' => now(),
            'checked_out_by_id' => $by->id,
            // Taking it out again after a return re-opens the line.
            'returned_at' => null,
            'returned_by_id' => null,
            'returned_quantity' => null,
        ])->save();

        self::notePacked($line->shoot, $by);
    }

    public static function pickUpAll(Shoot $shoot, User $by): void
    {
        $lines = $shoot->kits()->whereNull('checked_out_at')->get();

        foreach ($lines as $line) {
            $line->forceFill(['checked_out_at' => now(), 'checked_out_by_id' => $by->id])->save();
        }

        if ($lines->isNotEmpty()) {
            self::notePacked($shoot, $by);
        }
    }

    /** It never actually went. */
    public static function putBack(ShootKit $line): void
    {
        $line->forceFill(['checked_out_at' => null, 'checked_out_by_id' => null])->save();
    }

    /**
     * It came back -- all of it, or some of it. A shortfall is recorded, not
     * argued with: a partial return is a missing item whether or not anyone
     * says so.
     */
    public static function giveBack(ShootKit $line, User $by, ?int $quantity = null, ?string $condition = null, ?string $note = null): void
    {
        $returned = $quantity ?? $line->quantity;

        $line->forceFill([
            'returned_at' => now(),
            'returned_by_id' => $by->id,
            'returned_quantity' => $returned,
            'condition' => $condition ?? ($returned < $line->quantity ? ShootKit::CONDITION_MISSING : null),
            'condition_note' => $note ?? $line->condition_note,
        ])->save();

        self::noteReturned($line->shoot, $by);
    }

    /** Everything back, in good order. Naming damage takes naming the item. */
    public static function giveBackAll(Shoot $shoot, User $by): void
    {
        $lines = $shoot->kits()->whereNotNull('checked_out_at')->whereNull('returned_at')->get();

        foreach ($lines as $line) {
            $line->forceFill([
                'returned_at' => now(),
                'returned_by_id' => $by->id,
                'returned_quantity' => $line->quantity,
            ])->save();
        }

        if ($lines->isNotEmpty()) {
            self::noteReturned($shoot, $by);
        }
    }

    /** Once per shoot, at the moment the last item is ticked. */
    private static function notePacked(Shoot $shoot, User $by): void
    {
        $shoot->load('kits');

        if ($shoot->isPacked() && ! $shoot->events()->where('type', ShootEvent::KIT_PICKED)->exists()) {
            ShootEvent::record($shoot, $by, ShootEvent::KIT_PICKED, $shoot->kits->count().' '.str('item')->plural($shoot->kits->count()));
        }
    }

    private static function noteReturned(Shoot $shoot, User $by): void
    {
        $shoot->load('kits.item');

        if (! $shoot->isFullyReturned() || $shoot->events()->where('type', ShootEvent::KIT_RETURNED)->exists()) {
            return;
        }

        $problems = $shoot->kits->filter(fn (ShootKit $l) => $l->condition !== null)
            ->map(fn (ShootKit $l) => $l->item?->name.' ('.strtolower((string) $l->conditionLabel()).')')
            ->values();

        ShootEvent::record(
            $shoot, $by, ShootEvent::KIT_RETURNED,
            $problems->isEmpty() ? 'All in good order' : 'Problems: '.$problems->implode(', '),
            ['problems' => $problems->count()],
        );
    }
}
