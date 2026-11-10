<?php

namespace App\Notifications;

use App\Notifications\Channels\FcmChannel;
use App\Services\Push\PushMessage;
use Illuminate\Notifications\Notification;

/**
 * News about a venture someone watches on the dashboard: videos ready or
 * out, stock running low, or the morning summary. Push, the app inbox and
 * the portal bell all read it. See App\Console\Commands\VentureAlerts.
 */
class VentureUpdate extends Notification
{
    public function __construct(public string $title, public string $body, public string $tag, public ?string $url = null) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return [FcmChannel::class];
    }

    public function toFcm(object $notifiable): PushMessage
    {
        return new PushMessage($this->title, $this->body, $this->url ?? route('dashboard').'#ventures', $this->tag);
    }
}
