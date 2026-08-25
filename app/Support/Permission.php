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
        'credentials' => 'See passwords',
        'manage' => 'Manage',
    ];

    /**
     * "manage" is the module's own admin: whoever has it has everything else
     * in that module, and only that module. It exists so a lead writer can be
     * given the run of Scripts without being made an admin of the studio.
     */
    public const ABILITY_MANAGE = 'manage';

    /**
     * Every module, and everything the rest of the app needs to know about it.
     *
     * This list is the single source of truth. An entry here defines the gates
     * (AppServiceProvider), the checkboxes on the user form (permission-matrix),
     * and the sidebar link including its icon (_nav-modules) -- so adding a
     * module is one entry and one route group, and nothing else.
     *
     * `icon` lives here rather than in the Blade for exactly that reason: a map
     * in the view meant a new module silently fell back to a generic glyph and
     * looked like every other row until somebody noticed.
     *
     * @var array<string, array{label: string, group: string, icon: string, abilities: list<string>}>
     */
    public const MODULES = [
        'scripts' => [
            'label' => 'Scripts',
            'group' => 'Production',
            'icon' => 'document',
            'abilities' => ['view', 'create', 'edit', 'delete', 'comment', 'approve', 'manage'],
        ],
        /*
         * Shoots and Equipment are separate modules because they answer
         * different questions. A camera operator needs to plan a shoot and tick
         * the kit onto it; deciding what the studio owns and retiring a dead
         * camera is quartermaster work, and not everyone's. Equipment keeps
         * its own permission and routes -- only its sidebar row moved, to sit
         * nested under Shoots (see layouts/_nav-modules.blade.php) rather
         * than as a fourth top-level Production link.
         */
        'shoots' => [
            'label' => 'Shoots',
            'group' => 'Production',
            'icon' => 'camera',
            'abilities' => ['view', 'create', 'edit', 'delete', 'manage'],
        ],
        'equipment' => [
            'label' => 'Equipment',
            'group' => 'Production',
            'icon' => 'briefcase',
            'abilities' => ['view', 'create', 'edit', 'delete', 'manage'],
        ],
        /*
         * Client records, and the logins the studio holds on their behalf.
         *
         * "credentials" is a separate ability rather than part of view, because
         * the two are genuinely different jobs: somebody keeping client details
         * tidy has no reason to read their Instagram password, and the day that
         * password is misused the list of people who could have seen it should
         * be as short as the work allows.
         *
         * Grouped under Clients rather than Production because it is not
         * production work, and because a group of one reads better on the
         * permission matrix than a misfiled row.
         */
        'clients' => [
            'label' => 'Clients',
            'group' => 'Clients',
            'icon' => 'users',
            'abilities' => ['view', 'create', 'edit', 'delete', 'credentials', 'manage'],
        ],

        /*
         * What the public site shows. Separated from Production because it is
         * publishing work rather than making work: the person who keeps the
         * portfolio current is often not the person who shot it.
         */
        'portfolio' => [
            'label' => 'Portfolio',
            'group' => 'Website',
            'icon' => 'sparkles',
            'abilities' => ['view', 'create', 'edit', 'delete', 'manage'],
        ],
        'team' => [
            'label' => 'Team Page',
            'group' => 'Website',
            'icon' => 'user',
            'abilities' => ['view', 'create', 'edit', 'delete', 'manage'],
        ],

        /*
         * Running the studio. Enquiries has no `create` -- the only thing that
         * writes one is the public form on the landing page, and offering a
         * checkbox for something nobody can do is how a permission screen
         * stops being read.
         */
        /*
         * Studio ops used to be their own group, separate from Production.
         * Merged: two similarly-named groups ("Production" and "Studio")
         * sitting one above the other read as a distinction without a
         * difference to anyone who is not the person who built the
         * permission matrix.
         */
        'enquiries' => [
            'label' => 'Enquiries',
            'group' => 'Production',
            'icon' => 'mail',
            /*
             * The unread count next to the link. Declared as a callable pair
             * rather than a closure because this is a constant -- and declared
             * here at all so the badge follows the permission: it used to be
             * hardcoded in the admin half of the sidebar, which meant granting
             * Enquiries to a producer gave them the screen but not the one cue
             * that tells them to open it.
             */
            'badge' => [\App\Models\Enquiry::class, 'unreadCount'],
            'abilities' => ['view', 'edit', 'delete', 'manage'],
        ],
        'announcements' => [
            'label' => 'Announcements',
            'group' => 'Production',
            'icon' => 'megaphone',
            'abilities' => ['view', 'create', 'edit', 'delete', 'manage'],
        ],
        /*
         * Everyone's hours, not your own -- your own timesheet is a default and
         * needs no permission. `manage` is what awards points, which is why it
         * is separate from merely reading the month.
         */
        'timesheets' => [
            'label' => 'Timesheets',
            'group' => 'Production',
            'icon' => 'clock',
            'abilities' => ['view', 'manage'],
        ],
        /*
         * The master lists -- platforms, formats, objectives, industries, tags.
         * Only view and manage: there is no meaningful difference between
         * adding a tag and editing one, and pretending otherwise would put
         * four checkboxes on the form where two say the same thing.
         */
        'taxonomy' => [
            'label' => 'Master Data',
            'group' => 'Production',
            'icon' => 'grip',
            'abilities' => ['view', 'manage'],
        ],
        /*
         * Repeating studio duties (routines). Badge is overdue open
         * occurrences — same shape as Enquiry::unreadCount.
         */
        'routines' => [
            'label' => 'Routines',
            'group' => 'Production',
            'icon' => 'refresh',
            'badge' => [\App\Models\RoutineOccurrence::class, 'overdueCount'],
            'abilities' => ['view', 'create', 'edit', 'delete', 'manage'],
        ],
        /*
         * Scraping and analyzing a competitor's public Instagram, not one of
         * ours -- separate module from Portfolio/Clients for exactly that
         * reason. `manage` gates the Setup-adjacent bits inside the screen
         * itself (the settings link); connecting the paid API keys is its
         * own admin-only screen, not part of this module at all.
         */
        'competitors' => [
            'label' => 'Competitors',
            'group' => 'Production',
            'icon' => 'eye',
            'abilities' => ['view', 'create', 'delete', 'manage'],
        ],

        /*
         * The money. Split three ways rather than one module, because these
         * are three different jobs and the whole point of granting is being
         * able to give somebody one of them.
         *
         * Salaries is its own module for one reason: it is the only screen here
         * that tells you what a colleague earns. Folding it in with bills and
         * EMIs would mean anyone who reconciles the electricity bill also
         * learns the payroll.
         */
        'invoices' => [
            'label' => 'Invoices',
            'group' => 'Finance',
            'icon' => 'card',
            'abilities' => ['view', 'create', 'edit', 'delete', 'approve', 'manage'],
        ],
        'expenses' => [
            'label' => 'Expenses',
            'group' => 'Finance',
            'icon' => 'wallet',
            'abilities' => ['view', 'create', 'edit', 'delete', 'manage'],
        ],
        'salaries' => [
            'label' => 'Salaries',
            'group' => 'Finance',
            'icon' => 'trending-down',
            'abilities' => ['view', 'create', 'edit', 'delete', 'manage'],
        ],

        /*
         * Chakra App Studio's own line of work -- app/website builds for a
         * client, distinct from the Production side the rest of this file
         * is about. A SaasProduct is the software itself (backups, AMC
         * licensing); its own group keeps it from reading as a Production
         * module. No `credentials`/`approve` -- there is nothing here that
         * ability distinguishes.
         */
        'saas-products' => [
            'label' => 'SaaS Products',
            'group' => 'App Studio',
            'icon' => 'globe',
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
        'To-dos' => 'Plan their own day and track what they finish',
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
