<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * The admin sidebar, as designed: five short groups instead of ten
 * sections -- Today, Content, Clients, Money, Team & setup -- plus the
 * person's own ★ Favourites on top. Sub-screens (Equipment under Shoots,
 * the WhatsApp screens, Recurring under Invoices) appear under their parent
 * while you are in that part of the portal.
 *
 * Every row is still gated: a module needs `<module>.view` and its route.
 */
class AdminNav
{
    /**
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    public static function sections(User $user): array
    {
        $groups = [
            'Today' => [
                self::link('dashboard', 'Dashboard', 'home', 'dashboard', ['dashboard']),
                self::link('editor-updates', 'Editor Updates', 'film-cut', 'editor-updates', ['editor-updates', 'editor-updates.editor', 'editors.*']),
                self::link('inbox-desk', 'Comment & Message Checker', 'chat-check', 'inbox-desk.index', ['inbox-desk.*']),
                self::link('todos', $user->logsWork() ? 'Team To-dos' : 'To-dos', 'check-circle', 'todos.index', ['todos.index']),
                self::link('studio-map', 'Studio Map', 'map-pin', 'studio-map', ['studio-map']),
            ],
            'Content' => [
                self::module($user, 'planner', ['content-dashboard.*']),
                self::module($user, 'scripts'),
                self::module($user, 'shoots', ['shoots.*', 'equipment.*', 'my.shoots*'], 'shoots.calendar', [
                    self::gated($user, 'equipment.view', self::link('equipment', 'Equipment', 'briefcase', 'equipment.index', ['equipment.*'])),
                    self::link('my-shoots', 'Shoot updater', 'camera', 'my.shoots', ['my.shoots*']),
                ]),
                self::module($user, 'forecast'),
                self::module($user, 'deliveries'),
                self::module($user, 'announcements'),
                self::module($user, 'competitors'),
            ],
            'Clients' => [
                self::module($user, 'clients'),
                self::module($user, 'enquiries'),
                self::module($user, 'proposals'),
                self::module($user, 'quotations'),
                self::module($user, 'portfolio'),
                self::module($user, 'whatsapp-crm', ['whatsapp-crm.*'], 'whatsapp-crm.inbox.index', [
                    self::link('wa-activity', 'Activity', 'calendar', 'whatsapp-crm.activity.index', ['whatsapp-crm.activity.*']),
                    self::link('wa-campaigns', 'Campaigns', 'megaphone', 'whatsapp-crm.campaigns.index', ['whatsapp-crm.campaigns.*']),
                    self::link('wa-contacts', 'Contacts', 'users', 'whatsapp-crm.contacts.index', ['whatsapp-crm.contacts.*']),
                    self::link('wa-quick-replies', 'Quick Replies', 'chat', 'whatsapp-crm.quick-replies.index', ['whatsapp-crm.quick-replies.*']),
                    self::link('wa-templates', 'Templates', 'document', 'whatsapp-crm.templates.index', ['whatsapp-crm.templates.*']),
                    self::link('wa-flows', 'Automations', 'sparkles', 'whatsapp-crm.flows.index', ['whatsapp-crm.flows.*']),
                    self::link('wa-sessions', 'Sessions', 'clipboard-list', 'whatsapp-crm.flow-sessions.index', ['whatsapp-crm.flow-sessions.*']),
                ], 'WhatsApp'),
            ],
            'Money' => [
                self::link('money', 'Overview', 'wallet', 'money.index', ['money.index']),
                self::module($user, 'insights'),
                self::module($user, 'invoices', ['invoices.*', 'recurring.*'], null, [
                    self::gated($user, 'invoices.manage', self::link('recurring', 'Recurring', 'refresh', 'recurring.index', ['recurring.*'])),
                ]),
                self::module($user, 'expenses'),
                self::module($user, 'client-advances'),
                self::module($user, 'salaries'),
            ],
            'Team & setup' => [
                self::link('users', 'Users', 'user', 'users.index', ['users.*']),
                self::module($user, 'timesheets'),
                self::module($user, 'saas-products', ['saas-products.*', 'developer.saas-api'], null, [
                    self::gated($user, 'saas-products.manage', self::link('saas-api', 'SaaS API', 'globe', 'developer.saas-api', ['developer.saas-api'])),
                ]),
                self::module($user, 'developer'),
                self::link('settings', 'Settings', 'cog', 'settings.edit', ['settings.*', 'whatsapp.*', 'instagram-settings.*', 'notion.*', 'push.*', 'competitor-settings.*', 'content-accounts.*', 'brief-questions.*', 'invoice-template.*']),
            ],
        ];

        return collect($groups)
            ->map(fn (array $items, string $label) => ['label' => $label, 'items' => array_values(array_filter($items))])
            ->filter(fn (array $g) => $g['items'] !== [])
            ->values()
            ->all();
    }

    /** The person's pinned rows, in the order they pinned them. */
    public static function favourites(User $user, array $sections): array
    {
        $byKey = collect($sections)->flatMap(fn ($g) => $g['items'])->keyBy('key');

        return collect($user->nav_favourites ?? [])->map(fn ($key) => $byKey->get($key))->filter()->values()->all();
    }

    /** Every row key, for validating a pin. */
    public static function keys(User $user): array
    {
        return collect(self::sections($user))->flatMap(fn ($g) => $g['items'])->pluck('key')->all();
    }

    private static function link(string $key, string $label, string $icon, string $route, array $active, array $children = []): ?array
    {
        if (! Route::has($route)) {
            return null;
        }

        $children = array_values(array_filter($children));

        return [
            'key' => $key,
            'label' => $label,
            'icon' => $icon,
            'href' => route($route),
            'active' => request()->routeIs(...$active),
            'badge' => 0,
            'children' => $children,
        ];
    }

    /** A permissioned module, by its key in Permission::MODULES. */
    private static function module(User $user, string $module, array $active = [], ?string $route = null, array $children = [], ?string $label = null): ?array
    {
        $config = Permission::MODULES[$module] ?? null;
        $route ??= $module.'.index';
        if (! $config || ! $user->can($module.'.view')) {
            return null;
        }

        $item = self::link($module, $label ?? $config['label'], $config['icon'] ?? 'document', $route, $active ?: [$module.'.*'], $children);
        if ($item && isset($config['badge'])) {
            $item['badge'] = (int) call_user_func($config['badge']);
        }

        return $item;
    }

    private static function gated(User $user, string $ability, ?array $item): ?array
    {
        return $item && $user->can($ability) ? $item : null;
    }
}
