<?php

namespace App\Services\LiveActivities;

use App\Models\LiveActivityToken;
use App\Models\Shoot;
use App\Models\ShootCrew;
use App\Models\User;
use App\Notifications\CrewTripUpdated;
use App\Services\ShootRun;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

use function Illuminate\Support\defer;

/**
 * A crew member's shoot-day trip: on the way (with an ETA), arrived,
 * wrapped. Recorded on their crew row; the shoot's own departed/reached/
 * finished times move through ShootRun exactly as on My Shoots. Admins and
 * the rest of the crew get a notification, and every admin's iPhone gets
 * the CrewTrip Live Activity started or updated.
 */
class CrewTrip
{
    public const ON_THE_WAY = 'on_the_way';

    public const ARRIVED = 'arrived';

    public const WRAPPED = 'wrapped';

    public const STATUSES = [self::ON_THE_WAY, self::ARRIVED, self::WRAPPED];

    public static function report(Shoot $shoot, ShootCrew $crew, User $member, string $status, ?int $etaMinutes = null, ?float $lat = null, ?float $lng = null): string
    {
        $shoot->loadMissing('client:id,name');
        $place = $shoot->client?->name ?? $shoot->title;

        switch ($status) {
            case self::ON_THE_WAY:
                self::move(fn () => ShootRun::depart($shoot, $member));
                $eta = $etaMinutes !== null ? now()->addMinutes(max(0, min(600, $etaMinutes))) : null;
                $message = "{$member->name} is on the way to {$place}".($eta ? ' · ETA '.$eta->format('g:i A') : '');
                break;
            case self::ARRIVED:
                self::move(fn () => ShootRun::reached($shoot, $member));
                $eta = null;
                $message = "{$member->name} reached {$place}";
                break;
            case self::WRAPPED:
                if ($shoot->isInProgress()) {
                    try {
                        ShootRun::finish($shoot, $member); // ends the activities itself
                    } catch (RuntimeException) {
                    }
                } else {
                    self::ended($shoot);
                }
                $eta = null;
                $message = "{$place} wrapped";
                break;
            default:
                throw new RuntimeException('status must be on_the_way, arrived or wrapped.');
        }

        $crew->forceFill([
            'trip_status' => $status,
            'trip_eta_at' => $eta,
            'trip_lat' => $lat,
            'trip_lng' => $lng,
            'trip_updated_at' => now(),
        ])->save();

        $recipients = User::staff()->where(fn ($q) => $q->where('role', User::ROLE_ADMIN)
            ->orWhereIn('id', $shoot->crew()->pluck('user_id')))
            ->whereKeyNot($member->id)->get();
        try {
            Notification::send($recipients, new CrewTripUpdated($shoot, $message));
        } catch (Throwable $e) {
            Log::error('Crew trip notification failed.', ['shoot_id' => $shoot->id, 'error' => $e->getMessage()]);
        }

        if ($status !== self::WRAPPED) {
            defer(fn () => self::broadcast($shoot->fresh(), $message));
        }

        return $message;
    }

    /**
     * Run a ShootRun step. "Already wrapped" and the like are not news to a
     * trip report and are let go; kit not picked up is the one refusal the
     * person has to act on, so it reaches them.
     */
    private static function move(callable $step): void
    {
        try {
            $step();
        } catch (\App\Services\KitNotReady $e) {
            throw $e;
        } catch (Throwable) {
        }
    }

    /** @return array<string, mixed> */
    public static function attributes(Shoot $shoot): array
    {
        return ['shootId' => $shoot->id, 'title' => $shoot->title, 'client' => $shoot->client?->name ?? '', 'location' => $shoot->location];
    }

    /** @return array<string, mixed> */
    public static function state(Shoot $shoot, string $message): array
    {
        return [
            'crew' => $shoot->crew()->with('user:id,name')->whereIn('trip_status', [self::ON_THE_WAY, self::ARRIVED])->get()
                ->map(fn (ShootCrew $c) => ['name' => $c->user?->name ?? 'Crew', 'status' => $c->trip_status, 'etaAt' => $c->trip_eta_at?->timestamp])
                ->values()->all(),
            'updatedAt' => time(),
            'message' => $message,
        ];
    }

    public static function broadcast(Shoot $shoot, string $message): void
    {
        if (! LiveActivities::ready()) {
            return;
        }
        $shoot->loadMissing('client:id,name');
        $state = self::state($shoot, $message);
        foreach (User::query()->where('role', User::ROLE_ADMIN)->get() as $admin) {
            LiveActivities::updateOrStart($admin, LiveActivityToken::CREW_TRIP, self::attributes($shoot), $state,
                ['title' => $shoot->client?->name ?? $shoot->title, 'body' => $message],
                $shoot->id, "ct:{$admin->id}:{$shoot->id}", 6 * 3600, alertOnUpdate: true);
        }
    }

    /** The shoot is over: end its CrewTrip and Shoot activities everywhere. */
    public static function ended(Shoot $shoot): void
    {
        if (! LiveActivities::ready()) {
            return;
        }
        $shoot->loadMissing('client:id,name');
        LiveActivities::end(LiveActivities::updateTokens(LiveActivityToken::CREW_TRIP, null, $shoot->id),
            ['crew' => [], 'updatedAt' => time(), 'message' => ($shoot->client?->name ?? $shoot->title).' wrapped'], time() + 900);
        LiveActivities::end(LiveActivities::updateTokens(LiveActivityToken::SHOOT, null, $shoot->id),
            ['status' => 'wrapping', 'startedAt' => ($shoot->started_at ?? now())->timestamp], time() + 900);
    }
}
