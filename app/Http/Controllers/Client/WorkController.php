<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ResolvesClient;
use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use App\Support\ContentPieces;
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
 *
 * One piece, not one row -- the same cut planned for a reel and for YouTube
 * is one delivery shared in two places, and ContentPieces::collapse() is
 * where that is decided for this page and the content calendar alike.
 *
 * Split by type, because "174 pieces" answers nothing a client asks; they ask
 * how many reels went out this month. The split reads `source`, not
 * `post_type`: post_type is null on 1,262 of 2,106 rows and only ever says
 * "Reel" on the rest, whereas every row carries a source. It is the same
 * field the content calendar and the studio's board group by, so the client's
 * page and the studio's page cannot disagree about what a reel is.
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

        $pieces = ContentPieces::collapse($items)->sortByDesc('date')->values();
        $counts = ContentPieces::countsByChannel($pieces);

        // Only the types this client actually has. A jeweller who has only
        // ever had reels made should not be shown an empty "Stories" tab and
        // left wondering what they are not getting.
        $tabs = $counts
            ->filter(fn (int $count) => $count > 0)
            ->map(fn (int $count, string $source) => [
                'label' => ContentPieces::TYPES[$source],
                'count' => $count,
                // Taken off a real row rather than a second match() here, so a
                // tab and the rows it filters to cannot show different icons.
                'icon' => $items->firstWhere('source', $source)?->sourceIcon() ?? '📄',
            ]);

        // An unknown type in the URL falls back to everything rather than to
        // an empty page: the tab strip is the only thing that puts a type
        // there, so a mismatch means a stale bookmark, not a request.
        $type = (string) $request->query('type', 'all');

        if (! $tabs->has($type)) {
            $type = 'all';
        }

        $shown = $type === 'all'
            ? $pieces
            : $pieces->filter(fn (array $piece) => isset($piece['channels'][$type]))->values();

        return view('client.work', [
            'client' => $client,
            'tabs' => $tabs,
            'type' => $type,
            // Both tiles follow the filter. A client looking at 139 reels
            // under a tile reading 174 would be right to distrust the page.
            'total' => $shown->count(),
            'thisMonth' => $shown->filter(
                fn (array $piece) => $piece['date']->isSameMonth(now())
            )->count(),
            'countLabel' => $type === 'all' ? 'Pieces published' : $tabs[$type]['label'].' published',
            // Grouped in PHP: the key is a month and every dialect spells that
            // differently, which is the same reason EditorThroughput does it here.
            'months' => $shown
                ->groupBy(fn (array $piece) => $piece['date']->format('Y-m'))
                ->map(fn (Collection $rows) => [
                    'label' => $rows->first()['date']->format('F Y'),
                    'items' => $rows,
                ])
                ->values(),
        ]);
    }
}
