<?php

namespace App\Tools\Shoots;

use App\Models\Shoot;
use App\Models\User;
use App\Tools\Tool;
use App\Tools\ToolException;

/** One shoot in full (times, run state, crew with roles and call times): the shoot detail screen. */
class GetShoot extends Tool
{
    public function name(): string
    {
        return 'get_shoot';
    }

    public function title(): string
    {
        return 'Read one shoot';
    }

    public function group(): string
    {
        return 'Shoots';
    }

    public function description(): string
    {
        return 'One shoot in full: client, start/end, location, status, whether it is live, on-the-way/reached/'
            .'started/wrapped times, notes and crew with roles and call times. For anyone who can see all shoots, '
            .'or who is on its crew.';
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
        return $this->object(['shoot_id' => ['type' => 'integer']], ['shoot_id']);
    }

    public function handle(array $arguments, User $user): array
    {
        $shoot = Shoot::find((int) ($arguments['shoot_id'] ?? 0));

        $visible = $shoot && ($user->can('shoots.view')
            || $shoot->started_by_id === $user->id
            || $shoot->crew()->where('user_id', $user->id)->exists());
        if (! $visible) {
            throw new ToolException('There is no shoot with that id that you can see.');
        }

        return UpdateShoot::shootDetails($shoot) + ['client_logo_url' => $shoot->client?->logoUrl()];
    }
}
