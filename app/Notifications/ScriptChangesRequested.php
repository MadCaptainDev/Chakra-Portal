<?php

namespace App\Notifications;

use App\Models\Script;
use App\Notifications\Channels\FcmChannel;
use App\Services\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A client sent a script back for changes from their own portal
 * (Client\ScriptApprovalController::requestChanges()). The note itself is
 * left out of the push -- it is already on the script's comment thread,
 * and this is a nudge to go look, not the message.
 */
class ScriptChangesRequested extends Notification
{
    use Queueable;

    public function __construct(public Script $script) {}

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
            title: 'Changes requested: '.$this->script->clientLabel(),
            body: $this->script->title.' — the client asked for changes.',
            url: route('scripts.show', $this->script),
            tag: 'script-changes-requested-'.$this->script->id,
        );
    }
}
