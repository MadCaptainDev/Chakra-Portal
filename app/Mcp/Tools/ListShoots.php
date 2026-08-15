<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\Shoot;
use App\Models\User;

class ListShoots extends Tool
{
    public function name(): string
    {
        return 'list_shoots';
    }

    public function description(): string
    {
        return 'Shoots on the calendar: when, where, for which client, who is on the crew, and '
            .'whether the kit has been packed. Defaults to what is still coming up. Use this '
            .'for "what are we shooting this week" or "who is on the SVA shoot".';
    }

    public function permission(): ?string
    {
        return 'shoots.view';
    }

    public function schema(): array
    {
        return $this->object([
            'include_past' => ['type' => 'boolean', 'description' => 'Include shoots that have already happened. Defaults to false.'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'description' => 'How many to return. Defaults to 20.'],
        ]);
    }

    public function handle(array $arguments, User $user): array
    {
        $query = Shoot::with(['client', 'crew.user', 'kits'])->ordered();

        if (! ($arguments['include_past'] ?? false)) {
            $query->upcoming();
        }

        $shoots = $query->limit((int) ($arguments['limit'] ?? 20))->get();

        return [
            'count' => $shoots->count(),
            'shoots' => $shoots->map(fn (Shoot $shoot) => array_filter([
                'id' => $shoot->id,
                'title' => $shoot->title,
                'client' => $shoot->clientLabel(),
                'starts_at' => $shoot->starts_at?->toDateTimeString(),
                'ends_at' => $shoot->ends_at?->toDateTimeString(),
                'location' => $shoot->location,
                'status' => $shoot->statusLabel(),
                'crew' => $shoot->crew->map(fn ($member) => array_filter([
                    'name' => $member->user?->name,
                    'role' => $member->role,
                    'call_time' => $member->call_time ? substr($member->call_time, 0, 5) : null,
                ], fn ($value) => $value !== null))->values()->all(),
                'kit' => $shoot->kitSummary(),
                'kit_packed' => $shoot->isPacked() ?: null,
                'kit_problems' => $shoot->hasKitProblems() ?: null,
                'notes' => $shoot->notes,
            ], fn ($value) => $value !== null && $value !== []))->all(),
        ];
    }
}
