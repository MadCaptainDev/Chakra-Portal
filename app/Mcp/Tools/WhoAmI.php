<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\User;
use App\Support\Permission;

/**
 * Orientation. Without this the model has to guess whose diary it is reading.
 */
class WhoAmI extends Tool
{
    public function name(): string
    {
        return 'whoami';
    }

    public function description(): string
    {
        return 'Who this connection is acting as, what today\'s date is at the studio, '
            .'and which parts of the portal they can reach. Call this first in a conversation: '
            .'every other tool answers about this person unless told otherwise, and "today" '
            .'means the studio\'s today, which may not match the caller\'s.';
    }

    public function schema(): array
    {
        return $this->object([]);
    }

    public function handle(array $arguments, User $user): array
    {
        $modules = collect(Permission::modules())
            ->filter(fn (array $config, string $module) => $user->allows($module))
            ->keys()
            ->values()
            ->all();

        return [
            'name' => $user->name,
            'email' => $user->email,
            'is_admin' => $user->isAdmin(),
            'manages_people' => $user->managesAnyone(),
            // The studio runs on one clock. A model reasoning about "yesterday"
            // from its own timezone gets the wrong day often enough to matter.
            'today' => today()->toDateString(),
            'modules' => $modules,
            'note' => $user->isAdmin()
                ? 'Admin: reaches every module.'
                : 'Reaches their own timesheet and to-dos always, plus the modules listed.',
        ];
    }
}
