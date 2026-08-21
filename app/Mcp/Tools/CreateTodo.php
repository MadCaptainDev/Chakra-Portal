<?php

namespace App\Mcp\Tools;

use App\Mcp\McpToolException;
use App\Mcp\Tool;
use App\Models\Todo;
use App\Models\TodoUpdate;
use App\Models\User;
use App\Notifications\TodoAssigned;
use Illuminate\Support\Carbon;
use Throwable;

class CreateTodo extends Tool
{
    public function name(): string
    {
        return 'create_todo';
    }

    public function description(): string
    {
        return 'Put a piece of work on somebody\'s board. Defaults to the caller. Anybody may '
            .'assign work to anybody, and the to-do records who asked. A job spanning several '
            .'days is one to-do with a later due date, not one per day.';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function schema(): array
    {
        return $this->object([
            'title' => ['type' => 'string', 'description' => 'What needs doing.'],
            'person' => ['type' => 'string', 'description' => 'Who has to do it, by name or email. Defaults to the caller.'],
            'client' => ['type' => 'string', 'description' => 'Which client it is for. There is a catch-all for work spanning several.'],
            'starts_on' => ['type' => 'string', 'format' => 'date', 'description' => 'The day it lands on the board, YYYY-MM-DD. Defaults to today.'],
            'due_on' => ['type' => 'string', 'format' => 'date', 'description' => 'The day it is promised for. Defaults to the start day.'],
            'notes' => ['type' => 'string', 'description' => 'Anything the person needs to know.'],
        ], ['title']);
    }

    public function handle(array $arguments, User $user): array
    {
        $subject = People::assignable($arguments['person'] ?? null, $user);

        $starts = $this->date($arguments['starts_on'] ?? null) ?? today();
        $due = $this->date($arguments['due_on'] ?? null) ?? $starts->copy();

        if ($due->lt($starts)) {
            throw new McpToolException('The due date is before the day it starts.');
        }

        $todo = Todo::create([
            'user_id' => $subject->id,
            'assigned_by_id' => $user->id,
            'title' => $arguments['title'],
            'venture' => $this->client($arguments['client'] ?? null),
            'notes' => $arguments['notes'] ?? null,
            'starts_on' => $starts->toDateString(),
            'due_on' => $due->toDateString(),
        ]);

        // The history is the whole safety net for to-dos, so a row written any
        // other way than the web form does it would be a hole in it.
        TodoUpdate::record($todo, $user, TodoUpdate::CREATED, [
            'to_status' => $todo->status,
            'from_on' => $todo->starts_on,
            'to_on' => $todo->due_on,
        ]);

        TodoAssigned::notifyIfAssigned($todo);

        return [
            'id' => $todo->id,
            'title' => $todo->title,
            'for' => $subject->name,
            'starts_on' => $todo->starts_on->toDateString(),
            'due_on' => $todo->due_on->toDateString(),
            'status' => $todo->statusLabel(),
            'spans_days' => $todo->spanDays(),
        ];
    }

    private function date(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (Throwable) {
            throw new McpToolException('"'.$value.'" could not be read as a date. Use YYYY-MM-DD.');
        }
    }

    private function client(?string $value): string
    {
        return Clients::match($value);
    }
}
