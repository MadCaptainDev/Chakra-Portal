<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ResolvesClient;
use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use App\Support\ContentDashboard;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * What's coming, not just what already went out -- the client's own
 * dashboard already answers "how much published this month"
 * (DashboardController::index()); this answers "what, and when", before it
 * posts. Built on Client::contentItems() (the same venture-matched query
 * the dashboard's own counts use), not the ContentAccount/ContentDashboard
 * machinery the staff-side board runs on -- that machinery answers "how is
 * this account pacing against its monthly target", a studio question with
 * no business on a client's own screen.
 *
 * Three buckets, in the order a client would actually want to know them:
 * published, then what's scheduled to go out, then what's still being
 * made. Canceled items are never shown -- an idea that never became
 * anything is not something a client asked to see the history of.
 */
class ContentCalendarController extends Controller
{
    use ResolvesClient;

    public function index(Request $request): View
    {
        $client = $this->client($request);
        $month = $this->resolveMonth($request);
        [$since, $until] = ContentDashboard::monthRange($month);

        $items = $client->contentItems()
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'Canceled'))
            ->get();

        $published = $items->filter(fn (ContentItem $item) => $item->status === 'Published'
            && $item->published_date?->between($since, $until));

        $scheduled = $items->filter(fn (ContentItem $item) => $item->status === 'Scheduled'
            && $item->published_date?->between($since, $until));

        // "In progress" has no fixed calendar day yet -- that is what makes
        // it in progress -- so this is every such item regardless of month,
        // not filtered to $since/$until the way the other two buckets are.
        $inProgress = $items->filter(
            fn (ContentItem $item) => in_array($item->status, ContentDashboard::STATUS_GROUPS['in_progress'], true)
        );

        return view('client.content-calendar', [
            'client' => $client,
            'month' => $month,
            'published' => $published->sortBy('published_date')->values(),
            'scheduled' => $scheduled->sortBy('published_date')->values(),
            'inProgress' => $inProgress->sortBy('title')->values(),
        ]);
    }

    private function resolveMonth(Request $request): Carbon
    {
        $raw = (string) $request->query('month', '');

        if ($raw !== '') {
            try {
                return Carbon::createFromFormat('Y-m-d', $raw.'-01')->startOfDay();
            } catch (\Throwable) {
                // An unparsable month falls back rather than 500ing on a
                // typo in the address bar.
            }
        }

        return now()->startOfMonth();
    }
}
