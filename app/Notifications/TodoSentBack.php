<?php

namespace App\Notifications;

use App\Models\Todo;
use App\Notifications\Channels\FcmChannel;
use App\Services\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A manager sending finished work back.
 *
 * review_note is `requiredIf(REVIEW_REJECTED)` in TodoReviewController, so on
 * this path it is guaranteed non-empty -- there is no "sent back for no
 * reason" state to render around.
 */
class TodoSentBack extends Notification
{
    use Queueable;

    public function __construct(public Todo $todo) {}

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
            title: 'Sent back: '.Str::limit($this->todo->title, 80),
            body: Str::limit((string) $this->todo->review_note, 150),
            url: route('my.todos', ['date' => 'today']),
            tag: 'todo-'.$this->todo->id,
        );
    }
}
