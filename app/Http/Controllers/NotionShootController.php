<?php

namespace App\Http\Controllers;

use App\Models\NotionShoot;
use App\Services\Notion\NotionShootImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Notion's shoot planner, and the bridge into the portal's own Shoots
 * module.
 *
 * The studio plans shoots in Notion; the portal is where crew, kit and call
 * sheets live. Until these were joined the two were separate lists -- 82
 * shoots in Notion and none in the portal -- so nothing planned could ever
 * have a van packed for it.
 *
 * Importing is one-way and explicit. The Notion token is read-only, so a
 * shoot created in the portal cannot be pushed back, and a re-import
 * refreshes only the fields Notion owns (title, date, location, status),
 * never the crew or kit somebody added here.
 */
class NotionShootController extends Controller
{
    public function index(Request $request): View
    {
        $filter = $request->query('show', 'all');

        $shoots = NotionShoot::query()
            ->with(['mappedClient', 'shoot'])
            ->when($filter === 'unimported', fn ($q) => $q->whereDoesntHave('shoot'))
            ->when($filter === 'unmapped', fn ($q) => $q->whereNull('client_id'))
            ->orderByDesc('shoot_date')
            ->get();

        return view('notion-shoots.index', [
            'shoots' => $shoots,
            'filter' => $filter,
            'total' => NotionShoot::count(),
            'unmappedCount' => NotionShoot::whereNull('client_id')->count(),
            'unimportedCount' => NotionShoot::whereDoesntHave('shoot')->count(),
            'undatedCount' => NotionShoot::whereNull('shoot_date')->count(),
        ]);
    }

    /** Import every dated Notion shoot, creating or refreshing its portal shoot. */
    public function importAll(NotionShootImporter $importer): RedirectResponse
    {
        $result = $importer->importAll();

        $status = "Imported {$result['imported']}, refreshed {$result['updated']}.";

        if ($result['skipped'] > 0) {
            // Said plainly rather than counted as success: a shoot with no
            // date cannot go on a calendar, and the fix is in Notion.
            $status .= " Skipped {$result['skipped']} with no date in Notion.";
        }

        return redirect()->route('notion-shoots.index')->with('status', $status);
    }

    /** Import one shoot, for when only a particular one is wanted in the portal. */
    public function import(NotionShoot $notionShoot, NotionShootImporter $importer): RedirectResponse
    {
        $shoot = $importer->import($notionShoot);

        if (! $shoot) {
            return redirect()->route('notion-shoots.index')
                ->with('status', 'That shoot has no date in Notion, so it cannot be scheduled here yet.');
        }

        return redirect()->route('shoots.show', $shoot)
            ->with('status', 'Imported from Notion. Crew and kit can be added here.');
    }
}
