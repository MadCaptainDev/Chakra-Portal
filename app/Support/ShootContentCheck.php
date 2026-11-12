<?php

namespace App\Support;

use App\Models\Client;
use App\Models\ContentItem;
use App\Models\Shoot;
use App\Services\Videos\VideoLinker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Which completed shoots really have nothing in the Reel Planner yet.
 *
 * Shoot::scopeNeedsContentAdded() asks only Notion's Shoot<->Reel relation,
 * and the team mostly leaves the "Shoot" field on a reel empty: on 13 Nov
 * 2026 it called Thillai's 6 Nov shoot empty while five of its reels were
 * already "To Be Edited". It also flagged the portal half of a shoot booked
 * twice (portal + Notion) when the Notion half had all the reels.
 *
 * So a shoot that scope finds is still counted as in the planner when:
 *   - another shoot for the same client on the same day has reels linked
 *     (the same shoot, booked twice -- see shoots:fix-duplicates), or
 *   - the client has Reel Planner rows with no shoot linked, past the
 *     shooting stage ("To Be Edited" onwards), made from a week before the
 *     shoot to two weeks after it.
 *
 * A shoot named "test" is somebody trying the form, never missing content.
 */
class ShootContentCheck
{
    /** Planner rows made this many days either side of the shoot count for it. */
    private const DAYS_BEFORE = 7;

    private const DAYS_AFTER = 14;

    /**
     * The shoots, among those $query finds, with nothing in the planner.
     *
     * @param  Builder<Shoot>|null  $query  extra limits (dates); null = all
     * @return Collection<int, Shoot>
     */
    public static function missing(?Builder $query = null): Collection
    {
        $candidates = ($query ?? Shoot::query())->needsContentAdded()->get()
            ->reject(fn (Shoot $s) => preg_match('/^\s*test\b/i', (string) $s->title));
        if ($candidates->isEmpty()) {
            return $candidates->values();
        }

        $from = $candidates->min('starts_at')->copy()->subDays(self::DAYS_BEFORE)->startOfDay();
        $to = $candidates->max('starts_at')->copy()->addDays(self::DAYS_AFTER)->endOfDay();

        // Same client, same day, with reels linked: the twin of a double booking.
        $twinDays = Shoot::query()->where('status', Shoot::STATUS_COMPLETED)->whereNotNull('client_id')
            ->whereBetween('starts_at', [$from, $to])
            ->whereHas('notionShoot', fn (Builder $ns) => $ns->whereHas('contentItems'))
            ->get(['id', 'client_id', 'starts_at'])
            ->map(fn (Shoot $s) => $s->client_id.'|'.$s->starts_at->toDateString())
            ->flip();

        // Which client a planner row belongs to: the ventures connected to the
        // client's content accounts first ("Dr.Revanth" is client "Dr.Revanth,
        // Ortho" only that way), the timesheet venture names after that.
        $owner = [];
        Client::query()->whereIn('id', $candidates->pluck('client_id')->filter()->unique())->get()
            ->each(function (Client $c) use (&$owner) {
                foreach ($c->contentVentures() as $v) {
                    $owner[mb_strtolower(trim((string) $v))] ??= $c->id;
                }
            });
        $clientOf = fn (?string $venture) => $owner[mb_strtolower(trim((string) $venture))] ?? TimesheetVenture::clientIdFor($venture);

        // Reel Planner rows past shooting, with the "Shoot" field left empty.
        $unlinked = ContentItem::query()->visible()->where('source', ContentItem::SOURCE_REEL)
            ->whereNull('notion_shoot_id')->whereIn('status', VideoLinker::PAST_SHOOT)
            ->whereBetween('created_at', [$from, $to])
            ->get(['id', 'venture', 'created_at'])
            ->map(fn (ContentItem $i) => ['client' => $clientOf($i->venture), 'at' => $i->created_at])
            ->filter(fn (array $i) => $i['client'] !== null)
            ->groupBy('client');

        return $candidates->reject(function (Shoot $shoot) use ($twinDays, $unlinked) {
            if (! $shoot->client_id) {
                return false;
            }
            if ($twinDays->has($shoot->client_id.'|'.$shoot->starts_at->toDateString())) {
                return true;
            }
            $start = $shoot->starts_at->copy()->subDays(self::DAYS_BEFORE)->startOfDay();
            $end = $shoot->starts_at->copy()->addDays(self::DAYS_AFTER)->endOfDay();

            return collect($unlinked->get($shoot->client_id, []))->contains(fn (array $i) => $i['at']->between($start, $end));
        })->values();
    }
}
