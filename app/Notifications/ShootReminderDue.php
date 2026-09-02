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
 * Tomorrow's call sheet, the evening before -- crew only (this app has no
 * client-facing equivalent; see SendShootReminders's own doc block for
 * why). Same shape as ShootCrewAdded, which fires the moment somebody is
 * added to a shoot; this is the same information, fired again the night
 * before it actually happens, since being told once at booking time is not
 * the same as remembering it a week later.
 */
class ShootReminderDue extends Notification
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

        $callTime = null;
        if ($this->crew->call_time) {
            try {
                $callTime = Carbon::parse($this->crew->call_time)->format('g:ia');
            } catch (Throwable) {
                $callTime = null;
            }
        }

        return new PushMessage(
            title: 'Tomorrow: '.$shoot->title,
            body: $shoot->starts_at->format('D j M').($callTime ? ", call {$callTime}" : '')
                .($shoot->location ? " · {$shoot->location}" : ''),
            url: route('shoots.call-sheet', $shoot),
            tag: 'shoot-reminder-'.$shoot->id,
        );
    }
}
