<?php

namespace App\Http\Controllers;

use App\Models\NotionShoot;
use App\Services\Notion\ContentSyncService;
use App\Services\Notion\NotionShootImporter;
use Illuminate\Http\RedirectResponse;

/**
 * Pulls Notion's Shoots database and folds it straight into the portal's
 * own Shoots screen -- fetch, map clients, import. There is no separate
 * Notion Shoots page any more: this is a "sync now" button on the Shoots
 * index, not a bridge someone has to visit and operate on its own.
 */
class NotionShootController extends Controller
{
    public function sync(ContentSyncService $syncService, NotionShootImporter $importer): RedirectResponse
    {
        if (! ($syncService->sourceAvailability()[NotionShoot::SOURCE] ?? false)) {
            return redirect()->route('shoots.index')->with('status',
                "Couldn't reach the Shoots database in Notion. Check Setup → Notion, and that the database is shared with the integration.");
        }

        $syncService->syncSource(NotionShoot::SOURCE);
        $importer->autoMapClients();
        $result = $importer->importAll();

        $status = "Synced from Notion: {$result['imported']} new, {$result['updated']} refreshed.";

        if ($result['skipped'] > 0) {
            // Said plainly rather than folded into the count above: a
            // shoot with no date cannot go on a calendar, and the fix is
            // in Notion, not here.
            $status .= " {$result['skipped']} skipped (no date set in Notion).";
        }

        return redirect()->route('shoots.index')->with('status', $status);
    }
}
