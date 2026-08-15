<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ResolvesClient;
use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * What has gone out for this client, newest first.
 *
 * Title, date, platform and the link. Deliberately not the editor's name, the
 * effort tier or the hours: those are the studio's judgements about its own
 * people, and a client reading "Low effort" against something they paid for
 * is a conversation nobody wants and nobody meant to start.
 */
class WorkController extends Controller
{
    use ResolvesClient;

    /** Published is the only status a client has any business seeing. */
    private const PUBLISHED = 'Published';

    public function index(Request $request): View
    {
        $client = $this->client($request);

        $items = $client->contentItems()
            ->where('status', self::PUBLISHED)
            ->whereNotNull('published_date')
            // Nothing dated in the future: a planner row scheduled for next
            // week is a plan, and showing it as delivered is a promise.
            ->whereDate('published_date', '<=', today()->toDateString())
            ->orderByDesc('published_date')
            ->get(['id', 'title', 'published_date', 'post_type', 'notion_url', 'source']);

        return view('client.work', [
            'client' => $client,
            'total' => $items->count(),
            'thisMonth' => $items->filter(
                fn (ContentItem $item) => $item->published_date->isSameMonth(now())
            )->count(),
            // Grouped in PHP: the key is a month and every dialect spells that
            // differently, which is the same reason EditorThroughput does it here.
            'months' => $items
                ->groupBy(fn (ContentItem $item) => $item->published_date->format('Y-m'))
                ->map(fn (Collection $rows, string $key) => [
                    'label' => $rows->first()->published_date->format('F Y'),
                    'items' => $rows,
                ])
                ->values(),
        ]);
    }
}
