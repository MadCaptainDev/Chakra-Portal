<?php

namespace App\Support;

/**
 * The registry of what can be granted.
 *
 * One place declares every module and the abilities that make sense inside it,
 * so the permission matrix on the user form, the gates, the middleware and the
 * sidebar all read from the same list. Adding a module is an entry here -- no
 * migration, no new screen.
 *
 * Abilities are per-module on purpose: "approve" means something for scripts
 * and invoices and nothing for master data, and offering a checkbox that can
 * never matter is how a permission screen becomes noise.
 */
class Permission
{
    /** Every ability the system knows about, in the order they read best. */
    public const ABILITIES = [
        'view' => 'View',
        'create' => 'Create',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'comment' => 'Comment',
        'approve' => 'Approve',
        'manage' => 'Manage',
    ];

    /**
     * "manage" is the module's own admin: whoever has it has everything else
     * in that module, and only that module. It exists so a lead writer can be
     * given the run of Scripts without being made an admin of the studio.
     */
    public const ABILITY_MANAGE = 'manage';

    /**
     * @var array<string, array{label: string, group: string, abilities: list<string>}>
     */
    public const MODULES = [
        'scripts' => [
            'label' => 'Scripts',
            'group' => 'Production',
            'abilities' => ['view', 'create', 'edit', 'delete', 'comment', 'approve', 'manage'],
        ],
        /*
         * Shoots and Equipment are separate modules because they answer
         * different questions. A camera operator needs to plan a shoot and tick
         * the kit onto it; deciding what the studio owns and retiring a dead
         * camera is quartermaster work, and not everyone's.
         */
        'shoots' => [
            'label' => 'Shoots',
            'group' => 'Production',
            'abilities' => ['view', 'create', 'edit', 'delete', 'manage'],
        ],
        'equipment' => [
            'label' => 'Equipment',
            'group' => 'Production',
            'abilities' => ['view', 'create', 'edit', 'delete', 'manage'],
        ],
    ];

    /**
     * What every account reaches without anything being ticked.
     *
     * These are not modules and deliberately have no gates: the routes behind
     * them live in the plain `auth` group and every query inside is already
     * scoped to the signed-in user, so there is nothing to grant. They are
     * listed here only so the user form can say what "no permissions" means --
     * an empty matrix that reads as "this person can do nothing" is how an
     * admin ends up granting more than they meant to.
     *
     * Managing someone is not on this list because it is not granted here
     * either: it follows from being named as their manager.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        'Timesheet' => 'Log their own work and edit it',
        'Calendar' => 'See their own month',
        'Dashboard' => 'Their hours, points and studio announcements',
    ];

    /** @return array<string, array{label: string, group: string, abilities: list<string>}> */
    public static function modules(): array
    {
        return self::MODULES;
    }

    public static function isKnownModule(string $module): bool
    {
        return isset(self::MODULES[$module]);
    }

    public static function moduleLabel(string $module): string
    {
        return self::MODULES[$module]['label'] ?? ucfirst($module);
    }

    /**
     * The abilities offered for one module, or an empty list for a module that
     * is not registered -- so an unknown string can never be granted.
     *
     * @return list<string>
     */
    public static function abilitiesFor(string $module): array
    {
        return self::MODULES[$module]['abilities'] ?? [];
    }

    public static function isGrantable(string $module, string $ability): bool
    {
        return in_array($ability, self::abilitiesFor($module), true);
    }

    /**
     * Every "module.ability" pair, which is exactly the set of gates defined
     * at boot.
     *
     * @return list<string>
     */
    public static function allGateNames(): array
    {
        $names = [];

        foreach (self::MODULES as $module => $config) {
            foreach ($config['abilities'] as $ability) {
                $names[] = $module.'.'.$ability;
            }
        }

        return $names;
    }

    /**
     * Modules grouped for the sidebar and the permission matrix, preserving
     * the declaration order within each group.
     *
     * @return array<string, array<string, array{label: string, group: string, abilities: list<string>}>>
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (self::MODULES as $module => $config) {
            $grouped[$config['group']][$module] = $config;
        }

        return $grouped;
    }
}
