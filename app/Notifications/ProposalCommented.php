<?php

namespace App\Notifications;

use App\Models\ProposalComment;
use App\Notifications\Channels\FcmChannel;
use App\Services\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A client commenting on a proposal through its public link, pushed to the
 * staff member who created the proposal. The top-bar bell picks the same
 * comments up on its own (NotificationCenterController) -- this is the phone.
 */
class ProposalCommented extends Notification
{
    use Queueable;

    public function __construct(public ProposalComment $comment) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [FcmChannel::class];
    }

    public function toFcm(object $notifiable): PushMessage
    {
        $proposal = $this->comment->proposal;

        return new PushMessage(
            title: $this->comment->author_name.' commented on '.$proposal->title,
            body: Str::limit($this->comment->body, 140),
            url: route('proposals.show', $proposal).'#comment-'.$this->comment->id,
            tag: 'proposal-comment-'.$this->comment->id,
        );
    }
}
