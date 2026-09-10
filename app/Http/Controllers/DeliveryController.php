<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\View\View;

/**
 * Occasion clients' work -- shoot-only/edit-only/one-off jobs (see
 * Client::isOccasion()) -- read through the exact same Reel Planner data
 * as everyone else (Client::contentItems()), just without the targets,
 * pace or Forecast framing that assumes the studio posts for them.
 *
 * "Video Ready" is this client's finish line, not a still-in-progress
 * state the way it is for a regular client: nobody will ever mark this
 * item Published or Scheduled, because the studio hands it back and the
 * client posts it themselves.
 */
class DeliveryController extends Controller
{
    /** Notion status meaning "edited, waiting to go back to the client". */
    private const READY_STATUS = 'Video Ready';

    /** Everything that isn't ready yet and isn't cancelled. */
    private const IN_PROGRESS_STATUSES = ['Idea', 'To Be Shooted', 'To Be Edited', 'Edit in Progress', 'Under Review'];

    public function index(): View
    {
        $clients = Client::query()
            ->occasion()
            ->orderBy('name')
            ->get()
            ->map(function (Client $client) {
                $items = $client->contentItems()->get(['status']);

                return [
                    'client' => $client,
                    'in_progress' => $items->whereIn('status', self::IN_PROGRESS_STATUSES)->count(),
                    'ready' => $items->where('status', self::READY_STATUS)->count(),
                    'delivered' => $items->whereIn('status', ['Published', 'Scheduled'])->count(),
                ];
            });

        return view('deliveries.index', ['rows' => $clients]);
    }

    public function show(Client $client): View
    {
        $items = $client->contentItems()
            ->whereNotIn('status', ['Canceled'])
            ->with('notionShoot', 'scriptRecord')
            ->orderByRaw("
                CASE status
                    WHEN ? THEN 2
                    ELSE 1
                END
            ", [self::READY_STATUS])
            ->orderByDesc('shoot_date')
            ->get();

        return view('deliveries.show', [
            'client' => $client,
            'items' => $items,
            'readyStatus' => self::READY_STATUS,
        ]);
    }
}
