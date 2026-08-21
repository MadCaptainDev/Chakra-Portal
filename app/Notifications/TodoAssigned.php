<?php

namespace App\Notifications;

use App\Models\Todo;
use App\Notifications\Channels\FcmChannel;
use App\Services\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Str;
use Throwable;

/**
 * A to-do handed to somebody else.
 *
 * Two call sites create a Todo the same way -- My\TodoController::store()
 * and Mcp\Tools\CreateTodo -- so the guard and the send both live in
 * notifyIfAssigned() rather than being duplicated at each one. A to-do
 * Claude assigns on somebody's behalf must notify exactly like one a human
 * hands over; forgetting the MCP call site would mean it silently never does.
 */
class TodoAssigned extends Notification
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
        $from = $this->todo->assignedBy?->name;

        return new PushMessage(
            title: $from ? "New to-do from {$from}" : 'New to-do',
            body: Str::limit($this->todo->title, 150),
            url: route('my.todos', ['date' => $this->todo->starts_on->toDateString()]),
            tag: 'todo-'.$this->todo->id,
        );
    }

    /**
     * A to-do assigned to yourself is not an assignment -- isSelfAssigned()
     * already covers "nobody asked, I wrote this for me" (assigned_by_id is
     * null) and "I assigned it to myself" (assigned_by_id === user_id).
     */
    public static function notifyIfAssigned(Todo $todo): void
    {
        if ($todo->isSelfAssigned()) {
            return;
        }

        try {
            NotificationFacade::send($todo->user, new self($todo));
        } catch (Throwable $e) {
            Log::error('To-do assignment push failed.', [
                'todo_id' => $todo->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
