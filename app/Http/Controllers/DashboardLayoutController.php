<?php

namespace App\Http\Controllers;

use App\Support\DashboardLayout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Which Dashboard widgets a person shows, and in what order.
 *
 * No permission module and no policy, same reasoning as
 * DashboardWidgetController: every write here is scoped to
 * $request->user()->id, so there is nothing to grant and nobody else's
 * dashboard to reach. Arranging your own homepage is not a delegated
 * ability.
 */
class DashboardLayoutController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        // The customize panel is a client-side reorderable list (Alpine),
        // not a set of indexed form fields, so its final order/state is
        // serialized to one JSON string on submit rather than built as
        // widgets[0][key], widgets[1][key]... Decoded here, then validated
        // exactly as if it had arrived as a normal nested array.
        $decoded = json_decode((string) $request->input('widgets'), true);

        $validated = Validator::make(['widgets' => is_array($decoded) ? $decoded : null], [
            // The complete set, in the order to save -- the panel always
            // submits every widget, ticked or not, so "present but
            // unticked" and "absent" are the same signal: hidden.
            'widgets' => ['required', 'array', 'min:1'],
            'widgets.*.key' => ['required', 'string', 'distinct', Rule::in(array_keys(DashboardLayout::WIDGETS))],
            'widgets.*.visible' => ['nullable', 'boolean'],
        ])->validate();

        $order = collect($validated['widgets'])->pluck('key')->values()->all();

        $disabled = collect($validated['widgets'])
            ->reject(fn (array $w) => (bool) ($w['visible'] ?? false))
            ->pluck('key')
            ->values()
            ->all();

        $request->user()->forceFill([
            'dashboard_widgets_order' => $order,
            'dashboard_widgets_disabled' => $disabled,
        ])->save();

        return redirect()->route('dashboard')->with('status', 'Dashboard layout saved.');
    }

    /**
     * Back to the studio default -- every widget, default order. Simpler
     * than asking someone to re-tick seven checkboxes by hand.
     */
    public function reset(Request $request): RedirectResponse
    {
        $request->user()->forceFill([
            'dashboard_widgets_order' => null,
            'dashboard_widgets_disabled' => null,
        ])->save();

        return redirect()->route('dashboard')->with('status', 'Dashboard layout reset to default.');
    }
}
