<?php

namespace App\Tools\Studio;

use App\Models\User;
use App\Support\PlannerVisuals;
use App\Tools\Tool;

/** The studio team, for pickers (crew, to-do assignee). Names and roles only: no phones or pay. */
class ListTeam extends Tool
{
    public function name(): string
    {
        return 'list_team';
    }

    public function title(): string
    {
        return 'The studio team';
    }

    public function group(): string
    {
        return 'Team';
    }

    public function description(): string
    {
        return 'Everyone on the studio team (not clients): id, name, email, role, avatar. Use the name or email '
            .'with assign_shoot_crew, create_todo and update_shoot.remove_crew.';
    }

    public function availableTo(User $user): bool
    {
        return ! $user->isClient();
    }

    public function mcpOnly(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([]);
    }

    public function handle(array $arguments, User $user): array
    {
        return [
            'team' => User::staff()->orderBy('name')->get()->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'avatar_url' => PlannerVisuals::avatar($u),
                'is_you' => $u->id === $user->id,
            ])->values()->all(),
        ];
    }
}
