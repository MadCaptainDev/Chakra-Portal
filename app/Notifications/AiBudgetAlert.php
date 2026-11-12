<?php

namespace App\Notifications;

use App\Notifications\Channels\FcmChannel;
use App\Services\Push\PushMessage;
use Illuminate\Notifications\Notification;

/** Gemini spend reached 80% of the monthly limit, or the limit itself. See GeminiBudget. */
class AiBudgetAlert extends Notification
{
    public function __construct(public int $level, public float $spent, public float $cap) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return [FcmChannel::class];
    }

    public function toFcm(object $notifiable): PushMessage
    {
        $money = fn (float $v) => '$'.number_format($v, 2);

        return new PushMessage(
            $this->level >= 100 ? 'Gemini limit reached' : 'Gemini spend at 80%',
            $this->level >= 100
                ? "{$money($this->spent)} of {$money($this->cap)} used this month. Transcripts and captions are paused until "
                    .now()->startOfMonth()->addMonth()->format('j M').', or raise the limit under AI settings.'
                : "{$money($this->spent)} of {$money($this->cap)} used this month.",
            route('ai.edit').'#gemini-spend',
            'gemini-budget',
        );
    }
}
