<?php

namespace App\Http\Controllers;

use App\Models\ContentAccount;
use App\Models\DashboardContentWidget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Which content-account cards a person keeps on their own dashboard.
 *
 * No permission module and no policy: every write here is scoped to
 * `$request->user()->id`, so there is nothing to grant and nobody else's
 * dashboard to reach. Same reasoning as the other Permission::DEFAULTS
 * screens -- arranging your own homepage is not a delegated ability.
 */
class DashboardWidgetController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'accounts' => ['nullable', 'array'],
            'accounts.*' => ['integer', 'distinct', 'exists:content_accounts,id'],
            // Where to send them back to -- the same widget serves the admin
            // dashboard and /my, and a save should not silently move anyone
            // between the two.
            'redirect_to' => ['nullable', 'in:dashboard,my.dashboard'],
        ]);

        $ids = $validated['accounts'] ?? [];
        $userId = $request->user()->id;

        DB::transaction(function () use ($ids, $userId) {
            // Replaced wholesale rather than diffed: the form submits the
            // complete set every time, so anything absent was unticked, and
            // reconciling adds a way for the two to disagree.
            DashboardContentWidget::query()->where('user_id', $userId)->delete();

            foreach (array_values($ids) as $position => $accountId) {
                DashboardContentWidget::create([
                    'user_id' => $userId,
                    'content_account_id' => $accountId,
                    // Checkbox order is the card order, which is the only
                    // ordering the UI currently offers a way to express.
                    'sort_order' => $position,
                ]);
            }
        });

        $route = $validated['redirect_to'] ?? 'dashboard';

        return redirect()->route($route)->with('status', $ids === []
            // Unticking everything is a real choice, but it does not leave a
            // blank dashboard -- pinnedAccountsFor() falls back to the first
            // few -- so say so rather than let it look like the save failed.
            ? 'No accounts pinned, so the dashboard shows the first few again.'
            : 'Dashboard cards updated.');
    }
}
