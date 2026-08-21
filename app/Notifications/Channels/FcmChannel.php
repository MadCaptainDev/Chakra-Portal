<?php

namespace App\Notifications\Channels;

use App\Services\Push\PushMessage;
use App\Services\Push\PushSender;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one notification's toFcm() message to every device the
 * notifiable's routeNotificationForFcm() returns.
 *
 * Used as via() => [FcmChannel::class] -- a fully-qualified class name in
 * via() is resolved directly by Laravel's channel manager, so this needs
 * no Notification::extend() and leaves Notification::fake() working
 * unchanged for every notification, push or otherwise.
 *
 * NEVER THROWS. This inverts PushSender's own stance on purpose: PushSender
 * throws on total failure so the admin "send test push to me" screen can
 * show Google's error word-for-word, because that send was initiated by an
 * admin who is watching it happen. Everything reached through THIS class is
 * the opposite -- a side effect of an unrelated action (posting an
 * announcement, rejecting a timesheet day) -- and a manager must never get
 * a 500 because Firebase is unconfigured or having an outage. Catch
 * Throwable, log it, return.
 */
class FcmChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toFcm')) {
            return;
        }

        $tokens = method_exists($notifiable, 'routeNotificationForFcm')
            ? $notifiable->routeNotificationForFcm()
            : collect();

        if ($tokens->isEmpty()) {
            return;
        }

        try {
            $sender = PushSender::make();

            if (! $sender->canSend()) {
                return;
            }

            /** @var PushMessage $message */
            $message = $notification->toFcm($notifiable);

            $sender->send($tokens, $message);
        } catch (Throwable $e) {
            Log::error('Push notification failed.', [
                'notification' => $notification::class,
                'notifiable_id' => $notifiable->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
