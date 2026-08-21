<?php

namespace App\Notifications;

use App\Models\TimesheetDay;
use App\Notifications\Channels\FcmChannel;
use App\Services\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A manager rejecting a whole day of someone's timesheet.
 *
 * review_note is `requiredIf(REJECTED)` in TimesheetDayController, so on this
 * path it is guaranteed non-empty.
 */
class TimesheetDayRejected extends Notification
{
    use Queueable;

    public function __construct(public TimesheetDay $day) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [FcmChannel::class];
    }

    public function toFcm(object $notifiable): PushMessage
    {
        return new PushMessage(
            title: 'Timesheet day rejected: '.$this->day->worked_on->format('D j M'),
            body: Str::limit((string) $this->day->review_note, 150),
            url: route('my.timesheet', ['month' => $this->day->worked_on->format('Y-m')]),
            tag: 'timesheet-day-'.$this->day->id,
        );
    }
}
