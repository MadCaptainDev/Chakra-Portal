<?php

namespace App\Services;

use App\Models\Shoot;
use App\Models\ShootVideo;
use App\Models\User;
use App\Services\Notion\NotionShootStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use App\Support\PublicUpload;
use RuntimeException;

use function Illuminate\Support\defer;

/**
 * Running a shoot: starting it, filing each video, wrapping it.
 *
 * The rules live here rather than in the controller because two of them are
 * claims about the whole studio, not about one request -- you cannot be on
 * two live shoots at once, and a shoot cannot be wrapped twice. A controller
 * that owned those would have to be trusted not to drift from the command
 * line, the API, or whatever asks next.
 */
class ShootRun
{
    /**
     * The crew have set off for the location. Like reached(): anyone on the
     * shoot may say so, the first one counts, and a shoot already reached or
     * wrapped has nothing to set off for.
     *
     * @throws RuntimeException when the shoot has already wrapped
     */
    public static function depart(Shoot $shoot, User $crewMember): void
    {
        if ($shoot->hasWrapped()) {
            throw new RuntimeException('This shoot has already been wrapped.');
        }

        if ($shoot->hasDeparted() || $shoot->hasReached()) {
            return;
        }

        $shoot->forceFill([
            'departed_at' => now(),
            'departed_by_id' => $crewMember->id,
        ])->save();
    }

    /**
     * The crew are on location. Anyone on the shoot may say so -- director,
     * camera, whoever gets there first -- and the first one to say it is the
     * one recorded; a second tap from someone else is not news.
     *
     * @throws RuntimeException when the shoot has already wrapped
     */
    public static function reached(Shoot $shoot, User $crewMember): void
    {
        if ($shoot->hasWrapped()) {
            throw new RuntimeException('This shoot has already been wrapped.');
        }

        if ($shoot->hasReached()) {
            return;
        }

        $shoot->forceFill([
            'reached_at' => now(),
            'reached_by_id' => $crewMember->id,
        ])->save();

        self::announceReached($shoot, $crewMember);
    }

    /**
     * Tell the studio, once per shoot. After the response, because the person
     * tapping is standing on location on a phone signal, and a producer's
     * WhatsApp delivery is not something they should wait on.
     */
    private static function announceReached(Shoot $shoot, User $crewMember): void
    {
        defer(fn () => ShootReachedAlert::send($shoot, $crewMember));
    }

    /**
     * Begin the shoot.
     *
     * The one-at-a-time rule is per person, not per studio: two units with
     * separate crews genuinely do shoot in parallel, and a studio-wide lock
     * would refuse the second one for no reason. What it will not allow is
     * the same person being the camera on two live shoots, which is the case
     * that actually corrupts the record.
     *
     * @throws RuntimeException when this person already has one running
     */
    public static function start(Shoot $shoot, User $crewMember): void
    {
        if ($shoot->isInProgress()) {
            return; // Already running -- a second tap is not an error.
        }

        if ($shoot->hasWrapped()) {
            throw new RuntimeException('This shoot has already been wrapped.');
        }

        /*
         * Locked, because two taps a second apart on a phone with a bad
         * signal is the normal way this gets called twice. Without the lock
         * both reads see "nothing running" and both write a start.
         */
        $firstArrival = ! $shoot->hasReached();

        DB::transaction(function () use ($shoot, $crewMember) {
            $running = self::runningFor($crewMember, lock: true)->first();

            if ($running !== null && $running->isNot($shoot)) {
                throw new RuntimeException(
                    'You are still on "'.$running->title.'". Finish that before starting this one.'
                );
            }

            // Rolling means they got there, even if nobody tapped Reached.
            $shoot->forceFill([
                'reached_at' => $shoot->reached_at ?? now(),
                'reached_by_id' => $shoot->reached_by_id ?? $crewMember->id,
                'started_at' => now(),
                'finished_at' => null,
                'started_by_id' => $crewMember->id,
            ])->save();
        });

        NotionShootStatus::push($shoot, NotionShootStatus::SHOOTING);

        // Nobody tapped Reached -- starting is the arrival, so say so once.
        if ($firstArrival) {
            self::announceReached($shoot, $crewMember);
        }
    }

    /**
     * File one video against a running shoot.
     *
     * position is assigned here rather than by the caller so that two crew
     * filing at once cannot both claim the same number.
     */
    public static function recordVideo(
        Shoot $shoot,
        User $crewMember,
        string $name,
        ?UploadedFile $photo = null,
        ?string $notes = null,
        ?int $scriptId = null,
    ): ShootVideo {
        /*
         * Two different refusals, because "not in progress" covers two
         * opposite mistakes and telling a wrapped shoot to start would send
         * somebody looking for a button that is deliberately not there.
         */
        if ($shoot->hasWrapped()) {
            throw new RuntimeException('This shoot has already been wrapped.');
        }

        if (! $shoot->isInProgress()) {
            throw new RuntimeException('Start the shoot before adding videos.');
        }

        // Outside the transaction: moving the file is the slow part, and a
        // stored file with no row is recoverable in a way that a locked
        // table during an upload is not.
        $photoPath = $photo ? PublicUpload::store($photo, 'shoot-videos') : null;

        return DB::transaction(function () use ($shoot, $crewMember, $name, $photoPath, $notes, $scriptId) {
            $position = (int) ShootVideo::query()
                ->where('shoot_id', $shoot->id)
                ->lockForUpdate()
                ->max('position');

            return ShootVideo::create([
                'shoot_id' => $shoot->id,
                'script_id' => $scriptId,
                'recorded_by_id' => $crewMember->id,
                'name' => $name,
                'photo_path' => $photoPath,
                'notes' => $notes,
                'position' => $position + 1,
            ]);
        });
    }

    /**
     * Wrap the shoot.
     *
     * Also marks the booking completed: the crew saying "that's a wrap" is
     * the most reliable signal the portal ever gets that the shoot happened,
     * and leaving it `confirmed` would keep it on the producer's list of
     * things still to chase.
     */
    public static function finish(Shoot $shoot, User $crewMember): void
    {
        if (! $shoot->isInProgress()) {
            throw new RuntimeException('This shoot is not running.');
        }

        $shoot->forceFill([
            'finished_at' => now(),
            'status' => Shoot::STATUS_COMPLETED,
        ])->save();

        NotionShootStatus::push($shoot, NotionShootStatus::AFTER_WRAP);
    }

    /**
     * Shoots this person is crew on (or started) that are running right now.
     *
     * Covers both because the producer who started it on the studio iPad is
     * not always listed as crew, and they are still the person holding it
     * open.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Shoot>
     */
    public static function runningFor(User $crewMember, bool $lock = false)
    {
        $query = Shoot::query()
            ->inProgress()
            ->where(fn ($q) => $q
                ->where('started_by_id', $crewMember->id)
                ->orWhereHas('crew', fn ($c) => $c->where('user_id', $crewMember->id)));

        return $lock ? $query->lockForUpdate() : $query;
    }
}
