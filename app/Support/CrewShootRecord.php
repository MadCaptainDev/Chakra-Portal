<?php

namespace App\Support;

use App\Models\Shoot;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Past shoots, per crew member: what My Shoots shows a person about
 * themselves and what Shoots → Crew record shows a manager about everyone.
 *
 * "Past" is anything that started before today. Cancelled shoots never
 * count -- nobody went. Shoots still marked planned/confirmed after their
 * date do count: most were imported from Notion, where the status is often
 * left behind once the day is over, and the crew list is what says who went.
 */
class CrewShootRecord
{
    /**
     * Past shoots this person was on (everyone's, for null), newest first,
     * with the videos that came out of each:
     *
     * - the Reel Planner reels linked to the shoot in Notion (its "Shoot"
     *   relation) -- for most past shoots the only record of what was shot;
     * - videos the crew logged in the app on the day (ShootVideo), which
     *   only shoots run through My Shoots have;
     * - Notion's "No Of Videos", the number planned.
     */
    public static function shoots(?User $person): Collection
    {
        return Shoot::query()
            ->where('status', '!=', Shoot::STATUS_CANCELLED)
            ->where('starts_at', '<', today())
            ->when($person, fn ($q) => $q->whereHas('crew', fn ($c) => $c->where('user_id', $person->id)))
            ->with([
                'client:id,name',
                'crew.user:id,name',
                'videos.recordedBy:id,name',
                'notionShoot:id,video_count',
                'notionShoot.contentItems' => fn ($q) => $q->visible()
                    ->select('id', 'notion_shoot_id', 'source', 'title', 'status', 'published_date')
                    ->orderByRaw('published_date is null')->orderBy('published_date'),
            ])
            ->orderByDesc('starts_at')
            ->get();
    }

    /** The planner reels that came out of a shoot loaded by shoots(). */
    public static function reels(Shoot $shoot): Collection
    {
        return $shoot->notionShoot?->contentItems ?? collect();
    }

    /** Notion's "No Of Videos" when it is a plain number ("7"); it is free text, so often it is not. */
    public static function planned(Shoot $shoot): ?int
    {
        $raw = trim((string) $shoot->notionShoot?->video_count);

        return ctype_digit($raw) ? (int) $raw : null;
    }

    /**
     * The headline numbers for a list from shoots().
     *
     * @return array{total: int, this_month: int, last_month: int, clients: list<string>, first: ?Carbon, videos: int}
     */
    public static function summary(Collection $shoots): array
    {
        $thisMonth = today()->startOfMonth();
        $lastMonth = $thisMonth->copy()->subMonth();

        return [
            'total' => $shoots->count(),
            'videos' => $shoots->sum(fn (Shoot $s) => max(self::reels($s)->count(), $s->videos->count())),
            'this_month' => $shoots->filter(fn (Shoot $s) => $s->starts_at->gte($thisMonth))->count(),
            'last_month' => $shoots->filter(fn (Shoot $s) => $s->starts_at->between($lastMonth, $thisMonth->copy()->subSecond()))->count(),
            'clients' => $shoots->map(fn (Shoot $s) => $s->client?->name)->filter()->countBy()->sortDesc()->keys()->take(3)->values()->all(),
            'first' => $shoots->last()?->starts_at,
        ];
    }

    /** A list from shoots(), grouped "October 2026" => shoots, newest month first. */
    public static function byMonth(Collection $shoots): Collection
    {
        return $shoots->groupBy(fn (Shoot $s) => $s->starts_at->format('F Y'));
    }

    /**
     * Everyone who has been on a shoot: counts for each of the last $months
     * months, total, and their most recent shoot. Most shoots first.
     *
     * @return array{months: list<Carbon>, people: Collection}
     */
    public static function team(int $months = 6): array
    {
        $since = today()->startOfMonth()->subMonths($months - 1);
        $columns = collect(range($months - 1, 0))->map(fn (int $back) => today()->startOfMonth()->subMonths($back))->values();

        $rows = DB::table('shoot_crew')
            ->join('shoots', 'shoots.id', '=', 'shoot_crew.shoot_id')
            ->join('users', 'users.id', '=', 'shoot_crew.user_id')
            ->where('shoots.status', '!=', Shoot::STATUS_CANCELLED)
            ->where('shoots.starts_at', '<', today())
            ->get(['users.id as user_id', 'users.name', 'shoots.id as shoot_id', 'shoots.starts_at']);

        $people = $rows->groupBy('user_id')->map(function (Collection $mine) use ($columns, $since) {
            $dates = $mine->unique('shoot_id')->map(fn ($r) => Carbon::parse($r->starts_at));

            return [
                'id' => $mine->first()->user_id,
                'name' => $mine->first()->name,
                'months' => $columns->map(fn (Carbon $m) => $dates->filter(fn (Carbon $d) => $d->isSameMonth($m))->count())->all(),
                'recent' => $dates->filter(fn (Carbon $d) => $d->gte($since))->count(),
                'total' => $dates->count(),
                'last' => $dates->max(),
            ];
        })->sortByDesc('recent')->values();

        return ['months' => $columns->all(), 'people' => $people];
    }
}
