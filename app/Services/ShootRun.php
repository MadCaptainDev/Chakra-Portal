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
        DB::transaction(function () use ($shoot, $crewMember) {
            $running = self::runningFor($crewMember, lock: true)->first();

            if ($running !== null && $running->isNot($shoot)) {
                throw new RuntimeException(
                    'You are still on "'.$running->title.'". Finish that before starting this one.'
                );
            }

            $shoot->forceFill([
                'started_at' => now(),
                'finished_at' => null,
                'started_by_id' => $crewMember->id,
            ])->save();
        });

        NotionShootStatus::push($shoot, NotionShootStatus::SHOOTING);
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
    ): ShootVideo {
        if (! $shoot->isInProgress()) {
            throw new RuntimeException('Start the shoot before adding videos.');
        }

        // Outside the transaction: moving the file is the slow part, and a
        // stored file with no row is recoverable in a way that a locked
        // table during an upload is not.
        $photoPath = $photo ? PublicUpload::store($photo, 'shoot-videos') : null;

        return DB::transaction(function () use ($shoot, $crewMember, $name, $photoPath, $notes) {
            $position = (int) ShootVideo::query()
                ->where('shoot_id', $shoot->id)
                ->lockForUpdate()
                ->max('position');

            return ShootVideo::create([
                'shoot_id' => $shoot->id,
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
