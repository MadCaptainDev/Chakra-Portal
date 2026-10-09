<?php

namespace App\Notifications;

use App\Models\DailyCheck;
use App\Notifications\Channels\FcmChannel;
use App\Services\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** A team's end-of-day check came in with something not done. Admins only. */
class DailyCheckPending extends Notification
{
    use Queueable;

    public function __construct(public DailyCheck $check) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return [FcmChannel::class];
    }

    public function toFcm(object $notifiable): PushMessage
    {
        $missed = collect($this->check->missed())
            ->map(fn (array $i) => $i['label'].($i['note'] ? " ({$i['note']})" : ''))
            ->implode('; ');

        return new PushMessage(
            title: $this->check->team.': end of day not finished',
            body: $missed ?: 'Status: '.($this->check->status ?? 'Pending'),
            url: route('todos.index'),
            tag: 'daily-check-'.$this->check->id,
        );
    }
}
