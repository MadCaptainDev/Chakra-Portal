<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ResolvesClient;
use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use App\Support\ContentDashboard;
use App\Support\ContentPieces;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
 * A month grid, because a calendar is the shape of the question. Three lists
 * stacked down a page could tell a client that eleven things go out in
 * September; only a grid tells them that nine of the eleven land in the same
 * week and the fortnight before it is empty, which is the thing they would
 * actually want to say something about while there is still time.
 *
 * Canceled items are never shown -- an idea that never became anything is not
 * something a client asked to see the history of.
 *
 * Each bucket is collapsed by ContentPieces separately, never together: a
 * reel that is already out while its YouTube cut is still scheduled is two
 * facts about two different days, and merging them would put a date on the
 * grid for work that has not gone anywhere yet.
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

        $published = ContentPieces::collapse($items->filter(
            fn (ContentItem $item) => $item->status === 'Published'
                && $item->published_date?->between($since, $until)
        ));

        $scheduled = ContentPieces::collapse($items->filter(
            fn (ContentItem $item) => $item->status === 'Scheduled'
                && $item->published_date?->between($since, $until)
        ));

        // "In progress" has no fixed calendar day yet -- that is what makes
        // it in progress -- so this is every such item regardless of month,
        // not filtered to $since/$until the way the other two buckets are.
        // It sits under the grid rather than on it for the same reason: a
        // square would be claiming a date nobody has chosen.
        $inProgress = ContentPieces::collapse($items->filter(
            fn (ContentItem $item) => in_array($item->status, ContentDashboard::STATUS_GROUPS['in_progress'], true)
        ))->sortBy('title')->values();

        return view('client.content-calendar', [
            'client' => $client,
            'month' => $month,
            'weeks' => $this->weeks($month, $published, $scheduled),
            'published' => $published->sortBy('date')->values(),
            'scheduled' => $scheduled->sortBy('date')->values(),
            'inProgress' => $inProgress,
        ]);
    }

    /**
     * The grid: whole weeks, Monday to Sunday, padded out of the neighbouring
     * months so every row has seven squares and nothing shifts sideways.
     *
     * @param  Collection<int, array{date: ?Carbon, title: string, channels: array<string, mixed>}>  $published
     * @param  Collection<int, array{date: ?Carbon, title: string, channels: array<string, mixed>}>  $scheduled
     * @return Collection<int, Collection<int, array{date: Carbon, inMonth: bool, isToday: bool, pieces: Collection<int, array<string, mixed>>}>>
     */
    private function weeks(Carbon $month, Collection $published, Collection $scheduled): Collection
    {
        // Tagged here rather than in the view: which of the two a square is
        // showing decides its colour, and a view that had to re-derive that
        // from the status string would be a second place to get it wrong.
        $byDay = $published->map(fn (array $piece) => $piece + ['state' => 'published'])
            ->concat($scheduled->map(fn (array $piece) => $piece + ['state' => 'scheduled']))
            ->groupBy(fn (array $piece) => $piece['date']->toDateString());

        $cursor = $month->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY);
        $end = $month->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);

        $days = collect();

        while ($cursor <= $end) {
            $days->push([
                'date' => $cursor->copy(),
                // A square from the month either side is still a real day with
                // real work on it; it is just dimmed so the month reads first.
                'inMonth' => $cursor->isSameMonth($month),
                'isToday' => $cursor->isToday(),
                'pieces' => $byDay->get($cursor->toDateString(), collect()),
            ]);

            $cursor->addDay();
        }

        return $days->chunk(7)->values();
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
