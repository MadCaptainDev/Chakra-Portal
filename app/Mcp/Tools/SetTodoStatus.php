<?php

namespace App\Mcp\Tools;

use App\Mcp\McpToolException;
use App\Mcp\Tool;
use App\Models\Todo;
use App\Models\User;

class SetTodoStatus extends Tool
{
    public function name(): string
    {
        return 'set_todo_status';
    }

    public function description(): string
    {
        return 'Move a to-do to a new status: waiting, started, blocked, completed or cancelled. '
            .'Marking something blocked requires saying what by. Only the person who has to do '
            .'the work and the person who asked for it may move it. Get the id from list_todos.';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function schema(): array
    {
        return $this->object([
            'id' => ['type' => 'integer', 'description' => 'The to-do\'s id, from list_todos.'],
            'status' => [
                'type' => 'string',
                'enum' => array_keys(Todo::STATUSES),
                'description' => 'Where it is moving to.',
            ],
            'note' => ['type' => 'string', 'description' => 'What happened. Required when marking something blocked.'],
        ], ['id', 'status']);
    }

    public function handle(array $arguments, User $user): array
    {
        $todo = Todo::find($arguments['id']);

        /*
         * The same 404-not-403 the controller gives: somebody with no part in a
         * to-do has no business learning that it exists, and the two answers
         * must be identical or the difference between them is the leak.
         */
        if (! $todo || ! $todo->isWritableBy($user)) {
            throw new McpToolException(
                'There is no to-do #'.$arguments['id'].' that you can change. You can move work '
                .'that is yours to do, or that you asked somebody else for.'
            );
        }

        $note = $arguments['note'] ?? null;

        if ($arguments['status'] === Todo::STATUS_BLOCKED && ($note === null || trim($note) === '')) {
            throw new McpToolException('Say what it is blocked by. "Blocked" on its own helps nobody.');
        }

        $from = $todo->statusLabel();
        $todo->moveTo($arguments['status'], $user, $note);

        return [
            'id' => $todo->id,
            'title' => $todo->title,
            'was' => $from,
            'now' => $todo->statusLabel(),
        ];
    }
}
