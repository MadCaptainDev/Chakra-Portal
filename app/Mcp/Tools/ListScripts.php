<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\Script;
use App\Models\User;

class ListScripts extends Tool
{
    public function name(): string
    {
        return 'list_scripts';
    }

    public function description(): string
    {
        return 'Scripts in the pipeline: title, client, who is writing it, what stage it is at '
            .'and whether it is overdue. Defaults to scripts still open. Use this for "what am '
            .'I writing", "what is waiting on review", or "what scripts are late".';
    }

    public function permission(): ?string
    {
        return 'scripts.view';
    }

    public function schema(): array
    {
        return $this->object([
            'mine' => ['type' => 'boolean', 'description' => 'Only scripts the caller is writing. Defaults to false.'],
            'include_closed' => ['type' => 'boolean', 'description' => 'Include completed and archived scripts. Defaults to false.'],
            'status' => [
                'type' => 'string',
                'enum' => array_keys(Script::STATUSES),
                'description' => 'Only scripts at this stage.',
            ],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'description' => 'How many to return. Defaults to 25.'],
        ]);
    }

    public function handle(array $arguments, User $user): array
    {
        $query = Script::with(['client', 'writer', 'editor']);

        if (! ($arguments['include_closed'] ?? false)) {
            $query->open();
        }

        if ($arguments['mine'] ?? false) {
            $query->forWriter($user);
        }

        if (isset($arguments['status'])) {
            $query->where('status', $arguments['status']);
        }

        // Undated scripts last, then soonest first -- the same order the
        // Scripts screen uses, so the two cannot disagree about what is urgent.
        $scripts = $query
            ->orderByRaw('due_on IS NULL')
            ->orderBy('due_on')
            ->limit((int) ($arguments['limit'] ?? 25))
            ->get();

        return [
            'count' => $scripts->count(),
            'scripts' => $scripts->map(fn (Script $script) => array_filter([
                'id' => $script->id,
                'title' => $script->title,
                'client' => $script->clientLabel(),
                'status' => $script->statusLabel(),
                'priority' => $script->priorityLabel(),
                'writer' => $script->writer?->name,
                'editor' => $script->editor?->name,
                'due_on' => $script->due_on?->toDateString(),
                'overdue' => $script->isOverdue() ?: null,
                'duration' => $script->durationLabel(),
            ], fn ($value) => $value !== null))->all(),
        ];
    }
}
