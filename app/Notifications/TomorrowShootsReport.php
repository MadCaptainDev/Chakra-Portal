<?php

namespace App\Notifications;

use App\Notifications\Channels\FcmChannel;
use App\Services\Push\PushMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** "Tomorrow: 3 shoots, 2 not ready" -- see TomorrowShootCheck. */
class TomorrowShootsReport extends Notification
{
    /** @param  Collection<int, array{shoot: \App\Models\Shoot, problems: list<array<string, string>>}>  $rows */
    public function __construct(public Carbon $day, public Collection $rows) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return [FcmChannel::class];
    }

    public function toFcm(object $notifiable): PushMessage
    {
        $bad = $this->rows->filter(fn ($r) => $r['problems'] !== []);
        $n = $this->rows->count();

        $title = $bad->isEmpty()
            ? 'Tomorrow: '.$n.' '.Str::plural('shoot', $n).', all ready'
            : 'Tomorrow: '.$bad->count().' of '.$n.' '.Str::plural('shoot', $n).' not ready';

        $lines = $this->rows->map(function ($r) {
            $s = $r['shoot'];
            $when = $s->hasStartTime() ? $s->starts_at->format('g:i A').' ' : '';

            return ($r['problems'] ? '⚠ ' : '✓ ').$when.trim($s->title)
                .($r['problems'] ? ': '.collect($r['problems'])->pluck('label')->implode(', ') : '');
        });

        return new PushMessage($title, $lines->implode("\n"), route('shoots.calendar'), 'shoots-tomorrow');
    }
}
