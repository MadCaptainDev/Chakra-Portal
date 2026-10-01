<?php

namespace App\Notifications;

use App\Models\Script;
use App\Notifications\Channels\FcmChannel;
use App\Services\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A client approved a script from their own portal
 * (Client\ScriptApprovalController::approve()) -- pushed to whoever can act
 * on Scripts, since landing on Completed is otherwise a quiet status change
 * nobody notices until they happen to look at the board.
 */
class ScriptApproved extends Notification
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
            title: 'Script approved: '.$this->script->clientLabel(),
            body: $this->script->title.' was approved by the client.',
            url: route('scripts.show', $this->script),
            tag: 'script-approved-'.$this->script->id,
        );
    }
}
