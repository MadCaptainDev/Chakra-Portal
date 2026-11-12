<?php

namespace App\Services;

use App\Models\Shoot;
use App\Models\ShootKit;
use App\Services\Notion\NotionShootWriter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One shoot that ended up as two rows -- booked in the portal, and the same
 * shoot made again in Notion and imported -- folded back into one.
 *
 * Everything hanging off the dropped row (crew, kit, scripts, videos filed
 * on set, the day's events, the planner videos) moves to the kept row; run
 * times, notes and a real call time fill in where the kept row has none;
 * the Notion link is kept. Then the dropped row is deleted.
 */
class ShootMerger
{
    /**
     * Pairs that are certainly one shoot: same client, same day, exactly
     * one booked in the portal (no Notion page) and exactly one from Notion.
     *
     * @return Collection<int, array{0: Shoot, 1: Shoot}> [portal, notion]
     */
    public static function pairs(): Collection
    {
        return Shoot::query()
            ->where('status', '!=', Shoot::STATUS_CANCELLED)
            ->whereNotNull('client_id')
            ->get()
            ->groupBy(fn (Shoot $s) => $s->client_id.'|'.$s->starts_at->toDateString())
            ->map(function (Collection $group) {
                $portal = $group->whereNull('notion_shoot_id');
                $notion = $group->whereNotNull('notion_shoot_id');

                return $portal->count() === 1 && $notion->count() === 1 ? [$portal->first(), $notion->first()] : null;
            })
            ->filter()
            ->values();
    }

    /** Shoots on the same client and day that are both from Notion: two Notion pages, for a person to judge. */
    public static function notionSide(): Collection
    {
        return Shoot::query()
            ->where('status', '!=', Shoot::STATUS_CANCELLED)
            ->whereNotNull('client_id')->whereNotNull('notion_shoot_id')
            ->with('notionShoot:id,notion_url,title')
            ->get()
            ->groupBy(fn (Shoot $s) => $s->client_id.'|'.$s->starts_at->toDateString())
            ->filter(fn (Collection $g) => $g->count() > 1)
            ->values();
    }

    /** The row with more of the day on it is kept. */
    public static function weight(Shoot $s): int
    {
        return $s->videos()->count() * 5 + $s->events()->count() * 3 + $s->kits()->count() * 2 + $s->crew()->count()
            + ($s->started_at ? 10 : 0) + ($s->hasStartTime() ? 1 : 0);
    }

    /** @return Shoot the row kept */
    public static function merge(Shoot $a, Shoot $b): Shoot
    {
        [$keep, $drop] = self::weight($a) >= self::weight($b) ? [$a, $b] : [$b, $a];

        return NotionShootWriter::mute(fn () => DB::transaction(function () use ($keep, $drop) {
            $notionId = $keep->notion_shoot_id ?? $drop->notion_shoot_id;

            // Crew: union.
            foreach ($drop->crew()->get() as $c) {
                if (! $keep->crew()->where('user_id', $c->user_id)->exists()) {
                    $c->forceFill(['shoot_id' => $keep->id])->save();
                }
            }

            // Kit: union; the bigger quantity, and whichever line was picked up.
            foreach ($drop->kits()->get() as $line) {
                $mine = $keep->kits()->where('equipment_item_id', $line->equipment_item_id)->first();
                if (! $mine) {
                    $line->forceFill(['shoot_id' => $keep->id])->save();
                } elseif ($line->checked_out_at && ! $mine->checked_out_at) {
                    $mine->forceFill(['quantity' => max($mine->quantity, $line->quantity)] + $line->only(['checked_out_at', 'checked_out_by_id', 'returned_at', 'returned_by_id', 'returned_quantity', 'condition', 'condition_note']))->save();
                }
            }

            // Scripts: union.
            $have = DB::table('shoot_script')->where('shoot_id', $keep->id)->pluck('script_id')->all();
            DB::table('shoot_script')->where('shoot_id', $drop->id)->whereNotIn('script_id', $have)->update(['shoot_id' => $keep->id]);

            // Everything else simply moves.
            DB::table('shoot_videos')->where('shoot_id', $drop->id)->update(['shoot_id' => $keep->id]);
            DB::table('shoot_events')->where('shoot_id', $drop->id)->update(['shoot_id' => $keep->id]);
            DB::table('videos')->where('shoot_id', $drop->id)->update(['shoot_id' => $keep->id]);
            DB::table('shoot_crew')->where('next_shoot_id', $drop->id)->update(['next_shoot_id' => $keep->id]);
            if (\Illuminate\Support\Facades\Schema::hasColumn('live_activity_tokens', 'shoot_id')) {
                DB::table('live_activity_tokens')->where('shoot_id', $drop->id)->update(['shoot_id' => $keep->id]);
            }

            // Fill gaps on the kept row from the dropped one.
            $fill = [];
            foreach (['ends_at', 'location', 'requested_at', 'departed_at', 'departed_by_id', 'reached_at', 'reached_by_id', 'started_at', 'started_by_id', 'finished_at',
                'footage_drive', 'footage_url', 'footage_filed_at', 'footage_filed_by_id', 'kit_waived_at', 'kit_waived_by_id', 'kit_waived_reason', 'created_by_id'] as $col) {
                if ($keep->{$col} === null && $drop->{$col} !== null) {
                    $fill[$col] = $drop->{$col};
                }
            }
            if (! $keep->hasStartTime() && $drop->hasStartTime()) {
                $fill['starts_at'] = $drop->starts_at;
            }
            if (filled($drop->notes) && ! str_contains((string) $keep->notes, (string) $drop->notes)) {
                $fill['notes'] = trim(((string) $keep->notes)."\n\n".$drop->notes);
            }
            // A confirmed or later status beats "planned".
            $rank = [Shoot::STATUS_PLANNED => 0, Shoot::STATUS_CONFIRMED => 1, Shoot::STATUS_COMPLETED => 2];
            if (($rank[$drop->status] ?? -1) > ($rank[$keep->status] ?? -1)) {
                $fill['status'] = $drop->status;
            }

            $drop->forceFill(['notion_shoot_id' => null])->saveQuietly();
            $keep->forceFill($fill + ['notion_shoot_id' => $notionId])->saveQuietly();
            $drop->delete();

            return $keep->fresh();
        }));
    }
}
