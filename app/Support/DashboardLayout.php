<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The studio Dashboard's own widgets: what exists, the order nobody has
 * customized yet, and one person's actual show/hide + order on top of that.
 *
 * Each key names a Blade partial at resources/views/dashboard/widgets/{key}.
 * Widgets are deliberately section-sized (Money, Team, Delivery), not one
 * per stat tile -- a fully customizable dashboard of forty draggable tiles
 * is a different, much bigger feature than "let people hide what they don't
 * use and put the rest in their own order", which is what was asked for.
 */
class DashboardLayout
{
    /** @var array<string, string> key => label, in the order a fresh dashboard shows them. */
    public const WIDGETS = [
        'reel_today' => 'Reel Planner — Today',
        'needs_attention' => 'Needs Attention',
        'month_glance' => 'Month at a Glance',
        'missed_duties' => 'Missed Duties',
        'team' => 'Team',
        'delivery' => 'Delivery',
        'money' => 'Money',
    ];

    public const DEFAULT_ORDER = [
        'reel_today',
        'needs_attention',
        'month_glance',
        'missed_duties',
        'team',
        'delivery',
        'money',
    ];

    /**
     * This person's widgets, in their order, each marked visible or not --
     * what the Dashboard actually loops over to decide what to include and
     * in which order.
     *
     * A saved order that only lists some widgets (an old preference saved
     * before a new widget existed) gets that widget appended at the end
     * rather than never appearing; a saved order naming a widget that no
     * longer exists is silently dropped rather than 404ing a render.
     *
     * @return Collection<int, array{key: string, label: string, visible: bool}>
     */
    public static function resolveFor(?User $user): Collection
    {
        $order = $user?->dashboard_widgets_order;
        $disabled = collect($user?->dashboard_widgets_disabled ?? []);

        $keys = collect(is_array($order) && $order !== [] ? $order : self::DEFAULT_ORDER)
            ->filter(fn (string $key) => array_key_exists($key, self::WIDGETS))
            ->unique()
            ->values();

        // Anything real but missing from a stale saved order -- append it
        // rather than lose it. Runs even when $order was empty/null, which
        // is a no-op there since $keys already has everything.
        $missing = collect(array_keys(self::WIDGETS))->diff($keys);
        $keys = $keys->merge($missing);

        return $keys->map(fn (string $key) => [
            'key' => $key,
            'label' => self::WIDGETS[$key],
            'visible' => ! $disabled->contains($key),
        ]);
    }
}
