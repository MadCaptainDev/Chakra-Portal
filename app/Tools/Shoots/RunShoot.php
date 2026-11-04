<?php

namespace App\Tools\Shoots;

use App\Models\Shoot;
use App\Models\User;
use App\Services\ShootRun;
use App\Tools\Tool;
use App\Tools\ToolException;
use RuntimeException;

/**
 * The shoot day buttons from My Shoots → Run: on the way, reached, start
 * (the shoot goes live), wrap (it completes). The same ShootRun service, the
 * same rule: crew on the shoot, whoever started it, or an admin.
 */
class RunShoot extends Tool
{
    private const ACTIONS = ['on_the_way', 'reached', 'start', 'wrap'];

    public function name(): string
    {
        return 'run_shoot';
    }

    public function title(): string
    {
        return 'Shoot day: on the way, reached, start, wrap';
    }

    public function group(): string
    {
        return 'Shoots';
    }

    public function description(): string
    {
        return 'Move a shoot through its day, as the crew do on My Shoots: on_the_way (crew set off), '
            .'reached (on location; the team gets a staff alert), start (the shoot is live and its clock '
            .'runs) or wrap (it ends and becomes completed). For people on the shoot\'s crew, and admins. '
            .'A second "start" on a live shoot changes nothing.';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function mcpOnly(): bool
    {
        return true;
    }

    public function availableTo(User $user): bool
    {
        return ! $user->isClient();
    }

    public function schema(): array
    {
        return $this->object([
            'shoot_id' => ['type' => 'integer'],
            'action' => ['type' => 'string', 'enum' => self::ACTIONS],
        ], ['shoot_id', 'action']);
    }

    public function handle(array $arguments, User $user): array
    {
        $shoot = Shoot::find((int) ($arguments['shoot_id'] ?? 0))
            ?? throw new ToolException('There is no shoot with that id.');

        $mayRun = $user->isAdmin()
            || $shoot->started_by_id === $user->id
            || $shoot->crew()->where('user_id', $user->id)->exists();
        if (! $mayRun) {
            throw new ToolException('Only the shoot\'s crew or an admin can do that.');
        }

        $action = (string) ($arguments['action'] ?? '');
        try {
            match ($action) {
                'on_the_way' => ShootRun::depart($shoot, $user),
                'reached' => ShootRun::reached($shoot, $user),
                'start' => ShootRun::start($shoot, $user),
                'wrap' => ShootRun::finish($shoot, $user),
                default => throw new ToolException('action must be one of: '.implode(', ', self::ACTIONS).'.'),
            };
        } catch (RuntimeException $e) {
            throw new ToolException($e->getMessage());
        }

        return ['done' => $action, 'shoot' => UpdateShoot::shootDetails($shoot->refresh())];
    }
}
