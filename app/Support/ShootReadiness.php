<?php

namespace App\Support;

use App\Models\Shoot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Is a shoot ready to happen? The night-before questions, asked of the
 * portal's booking and its Notion card together:
 *
 *   client not confirmed   Notion "Client Conformation" is not Yes (or, for
 *                          a shoot booked in the portal, it is still Planned)
 *   content not ready      Notion "Content" is Not Yet
 *   no crew / no kit / no location / no start time
 *
 * "red" problems stop a shoot; "amber" ones make a messy morning.
 */
class ShootReadiness
{
    /** @return list<array{key: string, tone: string, label: string}> */
    public static function problems(Shoot $shoot): array
    {
        $shoot->loadMissing(['notionShoot', 'crew', 'kits']);
        $notion = $shoot->notionShoot;
        $out = [];

        if ($notion) {
            $answer = trim((string) $notion->client_confirmed);
            if ($answer === '') {
                // Most cards never had it filled in: say so, without crying wolf.
                $out[] = ['key' => 'unconfirmed', 'tone' => 'amber', 'label' => 'Confirmation not marked in Notion'];
            } elseif (strcasecmp($answer, 'Yes') !== 0) {
                $out[] = ['key' => 'unconfirmed', 'tone' => 'red', 'label' => 'Client not confirmed'];
            }
        } elseif ($shoot->status !== Shoot::STATUS_CONFIRMED) {
            $out[] = ['key' => 'unconfirmed', 'tone' => 'red', 'label' => 'Client not confirmed'];
        }

        if ($notion && strcasecmp(trim((string) $notion->content_ready), 'Not Yet') === 0) {
            $out[] = ['key' => 'content', 'tone' => 'red', 'label' => 'Content not ready'];
        } elseif (! $notion && $shoot->scripts()->doesntExist()) {
            $out[] = ['key' => 'content', 'tone' => 'amber', 'label' => 'No scripts linked'];
        }

        if ($shoot->crew->isEmpty()) {
            $out[] = ['key' => 'crew', 'tone' => 'red', 'label' => 'No crew'];
        }

        if (config('shoots.require_kit', true) && $shoot->kits->isEmpty() && ! $shoot->hasKitWaived()) {
            $out[] = ['key' => 'kit', 'tone' => 'amber', 'label' => 'No kit listed'];
        }

        if (blank($shoot->location) && blank($notion?->location)) {
            $out[] = ['key' => 'location', 'tone' => 'amber', 'label' => 'No location'];
        }

        if (! $shoot->hasStartTime()) {
            $out[] = ['key' => 'time', 'tone' => 'amber', 'label' => 'No start time'];
        }

        return $out;
    }

    /**
     * The shoots on a day, each with its problems, worst first.
     *
     * @return Collection<int, array{shoot: Shoot, problems: list<array<string, string>>}>
     */
    public static function forDay(Carbon $day): Collection
    {
        return Shoot::query()
            ->where('status', '!=', Shoot::STATUS_CANCELLED)
            ->whereBetween('starts_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->with(['client:id,name', 'notionShoot', 'crew.user:id,name', 'kits'])
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Shoot $s) => ['shoot' => $s, 'problems' => self::problems($s)])
            ->sortByDesc(fn (array $r) => collect($r['problems'])->where('tone', 'red')->count() * 10 + count($r['problems']))
            ->values();
    }
}
