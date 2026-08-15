<?php

namespace App\Mcp\Tools;

use App\Mcp\McpToolException;
use App\Mcp\Tool;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Support\Carbon;
use Throwable;

class ListTodos extends Tool
{
    public function name(): string
    {
        return 'list_todos';
    }

    public function description(): string
    {
        return 'What is on somebody\'s board for a given day: work in flight, work blocked, '
            .'work not started, and what closed. Defaults to the caller, today. Set '
            .'scope to "assigned" to see what the caller has handed to other people instead. '
            .'Use this for "what am I on today", "what is Aron working on", or "what is stuck".';
    }

    public function schema(): array
    {
        return $this->object([
            'date' => ['type' => 'string', 'format' => 'date', 'description' => 'Which day\'s board, YYYY-MM-DD. Defaults to today.'],
            'person' => ['type' => 'string', 'description' => 'Whose board. Only permitted for their manager, or an admin. Omit for your own.'],
            'scope' => [
                'type' => 'string',
                'enum' => ['own', 'assigned'],
                'description' => '"own" is work this person has to do. "assigned" is work they have given to others. Defaults to "own".',
            ],
            'open_only' => ['type' => 'boolean', 'description' => 'Leave out anything already completed or cancelled. Defaults to false.'],
        ]);
    }

    public function handle(array $arguments, User $user): array
    {
        $day = $this->day($arguments['date'] ?? null);
        $assigned = ($arguments['scope'] ?? 'own') === 'assigned';

        $subject = $assigned
            ? People::resolve($arguments['person'] ?? null, $user)
            : People::resolve($arguments['person'] ?? null, $user);

        $query = $assigned
            ? Todo::where('assigned_by_id', $subject->id)->where('user_id', '!=', $subject->id)
            : Todo::where('user_id', $subject->id);

        $query->onDay($day)->with(['user', 'assignedBy']);

        if ($arguments['open_only'] ?? false) {
            $query->open();
        }

        $todos = $query->get()
            ->sortBy(fn (Todo $todo) => [$todo->boardRank(), $todo->due_on->toDateString(), $todo->id])
            ->values();

        return [
            'person' => $subject->name,
            'date' => $day->toDateString(),
            'scope' => $assigned ? 'work handed to other people' : 'their own board',
            'count' => $todos->count(),
            'todos' => $todos->map(fn (Todo $todo) => array_filter([
                'id' => $todo->id,
                'title' => $todo->title,
                // Replayed from history, so an older day reads as it was rather
                // than as things stand now.
                'status' => Todo::STATUSES[$todo->statusOn($day)] ?? $todo->statusLabel(),
                'client' => $todo->venture,
                'for' => $assigned ? $todo->user->name : null,
                'asked_by' => $todo->isSelfAssigned() ? null : $todo->assignedBy?->name,
                'due' => $todo->due_on->toDateString(),
                'overdue' => $todo->isOverdueOn($day) ?: null,
                'spans_days' => $todo->spanDays() > 1 ? $todo->spanDays() : null,
                'notes' => $todo->notes,
            ], fn ($value) => $value !== null))->all(),
        ];
    }

    private function day(?string $value): Carbon
    {
        if ($value === null) {
            return today();
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (Throwable) {
            throw new McpToolException('That date could not be read. Use YYYY-MM-DD.');
        }
    }
}
