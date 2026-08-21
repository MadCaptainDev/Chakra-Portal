<?php

namespace App\Notifications;

use App\Models\ShootCrew;
use App\Notifications\Channels\FcmChannel;
use App\Services\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Being added to a shoot's crew.
 *
 * Fired only when the crew row is new (wasRecentlyCreated) -- editing
 * somebody's call time on an existing crew row must not re-notify them.
 * ShootCrewController::store() also skips this entirely when the person
 * added themselves, or the shoot is cancelled or already past.
 */
class ShootCrewAdded extends Notification
{
    use Queueable;

    public function __construct(public ShootCrew $crew) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [FcmChannel::class];
    }

    public function toFcm(object $notifiable): PushMessage
    {
        $shoot = $this->crew->shoot;

        // shoot_crew.call_time is a plain TIME column with no cast on the
        // model -- it arrives as a "H:i:s" string, not a Carbon instance.
        // Parsed defensively rather than trusted to always be well-formed.
        $callTime = null;
        if ($this->crew->call_time) {
            try {
                $callTime = Carbon::parse($this->crew->call_time)->format('g:ia');
            } catch (Throwable) {
                $callTime = null;
            }
        }

        $when = $shoot->starts_at->format('D j M').($callTime ? ", call {$callTime}" : '');

        return new PushMessage(
            title: 'Added to a shoot: '.$shoot->title,
            body: $when,
            url: route('shoots.call-sheet', $shoot),
            tag: 'shoot-crew-'.$this->crew->id,
        );
    }
}
