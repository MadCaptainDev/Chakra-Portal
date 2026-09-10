<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ContentItem;
use App\Support\ContentDashboard;
use App\Support\ContentForecast;
use Illuminate\View\View;

/**
 * "Who is about to run out of content, and is a shoot booked in time" --
 * read-only over ContentForecast, which does the actual arithmetic. See
 * that class's own doc block for the algorithm.
 */
class ForecastController extends Controller
{
    public function index(): View
    {
        return view('forecast.index', [
            'rows' => ContentForecast::forAllClients(),
        ]);
    }

    /**
     * One client's forecast, plus the items behind the "remaining" count and
     * their upcoming shoots -- the drill-down behind a portfolio row.
     */
    public function show(Client $client): View
    {
        $forecast = ContentForecast::forClient($client);

        $statuses = array_merge(
            ContentDashboard::STATUS_GROUPS['scheduled'],
            ContentDashboard::STATUS_GROUPS['in_progress'],
        );

        // Reel only, matching ContentForecast::remainingFor() -- this list
        // is the drill-down behind the "remaining" count, so it has to
        // count the same things that number counts.
        $items = $client->contentItems()
            ->where('source', ContentItem::SOURCE_REEL)
            ->whereIn('status', $statuses)
            ->with('notionShoot')
            ->orderByRaw('shoot_date is null, shoot_date')
            ->get();

        $upcomingShoots = $client->shoots()
            ->upcoming()
            ->ordered()
            ->get();

        return view('forecast.show', [
            'client' => $client,
            'forecast' => $forecast,
            'items' => $items,
            'upcomingShoots' => $upcomingShoots,
        ]);
    }
}
