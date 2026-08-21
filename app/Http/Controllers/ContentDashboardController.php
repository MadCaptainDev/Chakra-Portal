<?php

namespace App\Http\Controllers;

use App\Models\ContentAccount;
use App\Services\Notion\NotionSyncRunner;
use App\Support\ContentDashboard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * What each client account published in a month, per content type, against
 * target -- and what it actually did on Instagram.
 *
 * Reads the local Notion cache; see NotionSyncRunner for why Notion is not
 * read live and for the staleness refresh this triggers on the way in.
 */
class ContentDashboardController extends Controller
{
    public function index(Request $request): View
    {
        // A view of stale numbers refreshes them; a view of fresh ones costs
        // nothing. Deliberately before the data is read, so the page renders
        // what the refresh just wrote rather than being a sync behind.
        NotionSyncRunner::ensureFresh();

        $month = $this->resolveMonth($request);

        return view('content-dashboard.index', ContentDashboard::forMonth($month) + [
            'months' => ContentDashboard::availableMonths(),
            'lastSynced' => NotionSyncRunner::lastSyncedAt(),
            'targeted' => ContentDashboard::TARGETED,
        ]);
    }

    /**
     * One account's month, item by item, with the real Instagram post each
     * planned item turned into.
     *
     * The dashboard answers "did we hit the number"; this answers "which
     * pieces, and did they work" -- the question anybody asks the moment a
     * row looks wrong.
     */
    public function show(Request $request, ContentAccount $contentAccount): View
    {
        $month = $this->resolveMonth($request);

        return view('content-dashboard.show', [
            'account' => $contentAccount->load(['client', 'ventures']),
            'month' => $month,
            'months' => ContentDashboard::availableMonths(),
            'items' => ContentDashboard::itemsFor($contentAccount, $month),
            'targeted' => ContentDashboard::TARGETED,
        ]);
    }

    /**
     * Pull fresh data on demand.
     *
     * fresh: true clears the database-discovery cache too -- a person
     * pressing Refresh after sharing a new database in Notion means "look
     * again properly", not "re-read the same five databases".
     */
    public function refresh(Request $request): RedirectResponse
    {
        $status = NotionSyncRunner::run(fresh: true);

        return redirect()
            ->to($request->input('return_to') ?: route('content-dashboard.index'))
            ->with('status', $status);
    }

    private function resolveMonth(Request $request): Carbon
    {
        $raw = (string) $request->query('month');

        try {
            // Y-m only. A full date would let the month silently depend on
            // which day somebody happened to link to.
            return Carbon::createFromFormat('Y-m', $raw)->startOfMonth();
        } catch (\Throwable) {
            // Default to the newest month that actually has content, not
            // today: on the 1st of a quiet month "today" is an empty board
            // that looks like a broken sync.
            return ContentDashboard::availableMonths()->first() ?? now()->startOfMonth();
        }
    }
}
