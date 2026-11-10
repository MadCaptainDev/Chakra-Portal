<?php

namespace App\Services;

use App\Models\Script;
use App\Models\Shoot;
use App\Models\ShootCrew;
use App\Models\ShootEvent;
use App\Models\ShootKit;
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
    /** A video with the same name on the same shoot within this many minutes is the same video. */
    public const DUPLICATE_WINDOW_MINUTES = 2;

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

        self::refuseCancelled($shoot);

        if ($shoot->hasDeparted() || $shoot->hasReached()) {
            return;
        }

        self::requireKit($shoot);

        $shoot->forceFill([
            'departed_at' => now(),
            'departed_by_id' => $crewMember->id,
        ])->save();

        ShootEvent::record($shoot, $crewMember, ShootEvent::DEPARTED);
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

        self::refuseCancelled($shoot);

        if ($shoot->hasReached()) {
            return;
        }

        self::requireKit($shoot);

        $shoot->forceFill([
            'reached_at' => now(),
            'reached_by_id' => $crewMember->id,
        ])->save();

        ShootEvent::record($shoot, $crewMember, ShootEvent::REACHED);

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

        self::refuseCancelled($shoot);
        self::requireKit($shoot);

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

        if ($firstArrival) {
            ShootEvent::record($shoot, $crewMember, ShootEvent::REACHED);
        }
        ShootEvent::record($shoot, $crewMember, ShootEvent::STARTED);

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
        ?array $sectionLog = null,
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

        $video = DB::transaction(function () use ($shoot, $crewMember, $name, $photoPath, $notes, $scriptId, $sectionLog) {
            $position = (int) ShootVideo::query()
                ->where('shoot_id', $shoot->id)
                ->lockForUpdate()
                ->max('position');

            // The same video sent twice -- a double tap on a slow signal, two
            // requests a moment apart -- is one video. Under the lock above,
            // so two at once cannot both miss each other. A real second take
            // of a script, minutes later, is still its own video.
            $twin = ShootVideo::query()
                ->where('shoot_id', $shoot->id)
                ->where('name', $name)
                ->when($scriptId, fn ($q) => $q->where('script_id', $scriptId), fn ($q) => $q->whereNull('script_id'))
                ->where('created_at', '>=', now()->subMinutes(self::DUPLICATE_WINDOW_MINUTES))
                ->first();
            if ($twin) {
                return $twin;
            }

            return ShootVideo::create([
                'shoot_id' => $shoot->id,
                'script_id' => $scriptId,
                'recorded_by_id' => $crewMember->id,
                'name' => $name,
                'photo_path' => $photoPath,
                'notes' => $notes,
                'section_log' => $sectionLog ?: null,
                'seconds' => $sectionLog ? array_sum(array_column($sectionLog, 'seconds')) : null,
                'position' => $position + 1,
            ]);
        });

        // The twin kept its own still; this one's upload is not needed.
        if ($photoPath && $video->photo_path !== $photoPath) {
            PublicUpload::delete($photoPath);
        }

        if ($video->wasRecentlyCreated) {
            ShootEvent::record($shoot, $crewMember, ShootEvent::VIDEO, $video->name, ['video_id' => $video->id]);
        }

        // Recording a script's video means the script is done.
        if ($video->script_id) {
            Script::find($video->script_id)?->markRecorded($video->created_at);
        }

        return $video;
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

        $count = $shoot->videos()->count();
        ShootEvent::record($shoot, $crewMember, ShootEvent::WRAPPED, $count.' '.str('video')->plural($count));

        // End the shoot's Live Activities on everyone's iPhone.
        defer(fn () => rescue(fn () => \App\Services\LiveActivities\CrewTrip::ended($shoot), null, false));

        NotionShootStatus::push($shoot, NotionShootStatus::AFTER_WRAP);
    }

    /**
     * Kit is part of the shoot, not an extra: a shoot with nothing listed, or
     * with something listed and not picked up, does not leave. The message
     * says which, because the fix is different -- add the list, or tick it.
     *
     * An admin can let one shoot go without it (waiveKit), and the whole rule
     * can be switched off in config/shoots.php.
     *
     * @throws KitNotReady
     */
    public static function requireKit(Shoot $shoot): void
    {
        if (! config('shoots.require_kit', true) || $shoot->hasKitWaived()) {
            return;
        }

        $shoot->load('kits');

        if ($shoot->kits->isEmpty()) {
            throw new KitNotReady('This shoot has no kit listed. Add the kit you are taking before you leave.');
        }

        $left = $shoot->kits->whereNull('checked_out_at')->count();

        if ($left > 0) {
            throw new KitNotReady(
                $left === 1
                    ? 'One kit item is not picked up yet. Tick it before you leave.'
                    : $left.' kit items are not picked up yet. Tick them before you leave.'
            );
        }
    }

    /** An admin's "go without it", with the reason kept on the timeline. */
    public static function waiveKit(Shoot $shoot, User $admin, string $reason): void
    {
        if (! $admin->isAdmin()) {
            throw new RuntimeException('Only an admin can let a shoot leave without its kit check.');
        }

        $shoot->forceFill([
            'kit_waived_at' => now(),
            'kit_waived_by_id' => $admin->id,
            'kit_waived_reason' => mb_substr(trim($reason), 0, 500),
        ])->save();

        ShootEvent::record($shoot, $admin, ShootEvent::KIT_WAIVED, $reason);
    }

    private static function refuseCancelled(Shoot $shoot): void
    {
        if ($shoot->isCancelled()) {
            throw new RuntimeException('This shoot was cancelled.');
        }
    }

    /**
     * Call the shoot off -- before it starts, or in the middle of it.
     *
     * A shoot cancelled mid-run stops being "in progress" (see
     * Shoot::isInProgress), so its crew are free for the next one. What was
     * already filmed stays on the record.
     */
    public static function cancel(Shoot $shoot, User $by, ?string $reason = null): void
    {
        if ($shoot->isCancelled() && $shoot->cancelled_at !== null) {
            return;
        }

        if ($shoot->hasWrapped()) {
            throw new RuntimeException('This shoot has already been wrapped. A finished shoot cannot be cancelled.');
        }

        $reason = $reason !== null ? mb_substr(trim($reason), 0, 500) : null;

        $shoot->forceFill([
            'status' => Shoot::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by_id' => $by->id,
            'cancel_reason' => $reason ?: null,
        ])->save();

        ShootEvent::record($shoot, $by, ShootEvent::CANCELLED, $reason ?: null);

        defer(fn () => rescue(fn () => \App\Services\LiveActivities\CrewTrip::ended($shoot), null, false));

        NotionShootStatus::push($shoot, NotionShootStatus::CANCELLED);
    }

    /**
     * The next shoot this person is on today that has not been wrapped or
     * called off -- where they go if they are not going back to the office.
     */
    public static function nextFor(Shoot $after, User $crewMember): ?Shoot
    {
        return Shoot::query()
            ->whereKeyNot($after->id)
            ->whereNotIn('status', [Shoot::STATUS_CANCELLED, Shoot::STATUS_COMPLETED])
            ->whereNull('finished_at')
            ->whereBetween('starts_at', [now()->startOfDay(), now()->endOfDay()])
            ->whereHas('crew', fn ($c) => $c->where('user_id', $crewMember->id))
            ->with('client')
            ->orderBy('starts_at')
            ->first();
    }

    /**
     * Wrapped here, and going straight on to the next one.
     *
     * The kit travels with the crew: whatever is still out on this shoot and
     * wanted on the next moves across, and a next shoot with no list of its
     * own inherits this one's. The pick-up rule is not asked again -- they
     * are on the road, and there is nothing left to pick up from.
     *
     * @throws RuntimeException when this shoot is not wrapped, or there is no next one
     */
    public static function headToNext(Shoot $shoot, User $crewMember): Shoot
    {
        if (! $shoot->hasWrapped()) {
            throw new RuntimeException('Wrap this shoot first.');
        }

        $next = self::nextFor($shoot, $crewMember)
            ?? throw new RuntimeException('You have no other shoot today.');

        DB::transaction(function () use ($shoot, $next, $crewMember) {
            self::carryKit($shoot, $next, $crewMember);

            self::crewRow($shoot, $crewMember)?->forceFill([
                'after_wrap' => ShootCrew::AFTER_NEXT_SHOOT,
                'next_shoot_id' => $next->id,
            ])->save();

            if (! $shoot->events()->where('type', ShootEvent::NEXT_SHOOT)->where('user_id', $crewMember->id)->exists()) {
                ShootEvent::record($shoot, $crewMember, ShootEvent::NEXT_SHOOT, $next->title, ['next_shoot_id' => $next->id]);
            }

            if (! $next->hasDeparted() && ! $next->hasReached()) {
                $next->forceFill(['departed_at' => now(), 'departed_by_id' => $crewMember->id])->save();
                ShootEvent::record($next, $crewMember, ShootEvent::DEPARTED, 'Straight from '.$shoot->title, ['from_shoot_id' => $shoot->id]);
            }
        });

        return $next;
    }

    /** Move kit that is still out on $from onto $to's list, as picked up. */
    private static function carryKit(Shoot $from, Shoot $to, User $by): void
    {
        $out = $from->kits()->whereNotNull('checked_out_at')->whereNull('returned_at')->get();
        $inherit = $to->kits()->count() === 0;
        $moved = 0;

        foreach ($out as $line) {
            $target = $to->kits()->where('equipment_item_id', $line->equipment_item_id)->first();

            if ($target === null && $inherit) {
                $target = $to->kits()->create(['equipment_item_id' => $line->equipment_item_id, 'quantity' => $line->quantity]);
            }

            if ($target === null) {
                continue; // Not wanted on the next shoot: it stays on this one until returned.
            }

            if ($target->checked_out_at === null) {
                $target->forceFill(['checked_out_at' => now(), 'checked_out_by_id' => $by->id])->save();
            }

            // It left this shoot in good order -- for the next one, not the shelf.
            $line->forceFill([
                'returned_at' => now(),
                'returned_by_id' => $by->id,
                'returned_quantity' => $line->quantity,
            ])->save();
            $moved++;
        }

        if ($moved > 0) {
            ShootEvent::record($to, $by, ShootEvent::KIT_PICKED, $moved.' '.str('item')->plural($moved).' carried over from '.$from->title);
        }
    }

    /** Wrapped, and heading back. The time is this person's, not the shoot's. */
    public static function returnToOffice(Shoot $shoot, User $crewMember): void
    {
        if (! $shoot->hasWrapped() && ! $shoot->isCancelled()) {
            throw new RuntimeException('Wrap this shoot first.');
        }

        $row = self::crewRow($shoot, $crewMember);
        if ($row?->returning_at !== null) {
            return;
        }

        $row?->forceFill(['after_wrap' => ShootCrew::AFTER_OFFICE, 'returning_at' => now()])->save();
        ShootEvent::record($shoot, $crewMember, ShootEvent::RETURNING);
    }

    public static function backAtOffice(Shoot $shoot, User $crewMember): void
    {
        if (! $shoot->hasWrapped() && ! $shoot->isCancelled()) {
            throw new RuntimeException('Wrap this shoot first.');
        }

        $row = self::crewRow($shoot, $crewMember);
        if ($row?->back_at_office_at !== null) {
            return;
        }

        $row?->forceFill([
            'after_wrap' => ShootCrew::AFTER_OFFICE,
            'returning_at' => $row->returning_at ?? now(),
            'back_at_office_at' => now(),
        ])->save();
        ShootEvent::record($shoot, $crewMember, ShootEvent::BACK_AT_OFFICE);
    }

    /**
     * The footage is on a drive: which one, a link if there is one, and
     * which of the day's videos were copied. Every video has to be ticked --
     * "most of it is on the SSD" is how a clip goes missing a month later.
     *
     * @param  list<int>  $copiedVideoIds
     *
     * @throws RuntimeException
     */
    public static function fileFootage(Shoot $shoot, User $crewMember, string $drive, ?string $url, array $copiedVideoIds): void
    {
        if (! $shoot->hasWrapped()) {
            throw new RuntimeException('Wrap this shoot first.');
        }

        if (! in_array($drive, (array) config('shoots.drives'), true)) {
            throw new RuntimeException('Choose which drive the footage is on.');
        }

        $videos = $shoot->videos()->get();
        $missing = $videos->reject(fn (ShootVideo $v) => in_array($v->id, $copiedVideoIds, true) || $v->footage_copied_at !== null);

        if ($missing->isNotEmpty()) {
            throw new RuntimeException(
                'Tick every video once it is on the drive. Still to copy: '.$missing->pluck('name')->take(5)->implode(', ').'.'
            );
        }

        foreach ($videos->whereNull('footage_copied_at') as $video) {
            $video->forceFill(['footage_copied_at' => now()])->save();
        }

        $first = $shoot->footage_filed_at === null;

        $shoot->forceFill([
            'footage_drive' => $drive,
            'footage_url' => $url ?: null,
            'footage_filed_at' => $shoot->footage_filed_at ?? now(),
            'footage_filed_by_id' => $shoot->footage_filed_by_id ?? $crewMember->id,
        ])->save();

        if ($first) {
            ShootEvent::record(
                $shoot, $crewMember, ShootEvent::FOOTAGE_FILED,
                $videos->count().' '.str('video')->plural($videos->count()).' on '.$drive,
                array_filter(['drive' => $drive, 'url' => $url]),
            );
        }
    }

    private static function crewRow(Shoot $shoot, User $crewMember): ?ShootCrew
    {
        return $shoot->crew()->where('user_id', $crewMember->id)->first();
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
