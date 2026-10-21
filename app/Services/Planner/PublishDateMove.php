<?php

namespace App\Services\Planner;

use App\Models\ContentItem;
use App\Models\ContentItemChange;
use App\Models\User;
use App\Services\Notion\ContentSyncService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Moving a planner item's publish date from Chakra Planner.
 *
 * Notion is still the master copy, so the date is written THERE first and
 * the portal row is rebuilt from the page Notion hands back. Writing only the
 * portal row would be undone by the next sync; writing the portal first and
 * Notion "eventually" would leave the two disagreeing whenever Notion is
 * down -- so if Notion cannot take the change, nothing changes and the person
 * is told why (NotionWriteFailed).
 *
 * Two people moving the same item: the app sends the date it was showing
 * ($expectedFrom). If the portal or Notion already holds a different date,
 * the move is refused with the item as it now stands (PlannerConflict), so
 * the second person sees the first person's move instead of silently
 * overwriting it. Notion is checked too because somebody may have changed
 * the date in Notion itself since the last sync.
 */
class PublishDateMove
{
    public function __construct(private ContentSyncService $notion) {}

    /**
     * @param  string|null  $to  Y-m-d, or null to clear the date
     * @param  bool  $checkFrom  false when the app sent no "from" (no conflict check)
     */
    public function move(ContentItem $item, ?string $to, ?string $expectedFrom, bool $checkFrom, User $actor): ContentItem
    {
        $local = $item->published_date?->toDateString();

        if ($checkFrom && $local !== $expectedFrom) {
            throw new PlannerConflict($item, 'Someone already moved this to '.$this->say($local).'.');
        }
        if (! $this->notion->isConfigured()) {
            throw new NotionWriteFailed('Notion is not connected (Setup → Notion), so the date cannot be changed.');
        }
        if ($item->notion_missing_since) {
            throw new NotionWriteFailed('This item is no longer in Notion, so its date cannot be changed.');
        }

        try {
            $page = $this->notion->fetchPage($item->notion_page_id);
        } catch (Throwable $e) {
            throw new NotionWriteFailed($this->reason($e));
        }

        $property = $this->notion->publishedDateProperty($page);
        if ($property === null) {
            throw new NotionWriteFailed('This planner in Notion has no "Published Date" date property.');
        }

        $start = $property['date']['start'] ?? null;
        $inNotion = $start ? substr($start, 0, 10) : null;
        if ($checkFrom && $inNotion !== $expectedFrom) {
            // Changed in Notion since the last sync: bring the portal up to
            // date, then let the person decide again.
            $fresh = $this->notion->applyPage($page) ?? $item;
            throw new PlannerConflict($fresh, 'This was changed in Notion to '.$this->say($inNotion).'.');
        }
        if ($inNotion === $to) {
            return $item;
        }

        try {
            $page = $this->notion->updatePage($item->notion_page_id, [
                $property['name'] => ['date' => $to === null ? null : $this->dateValue($property['date'], $to)],
            ]);
        } catch (Throwable $e) {
            throw new NotionWriteFailed($this->reason($e));
        }

        $fresh = $this->notion->applyPage($page) ?? $item;
        ContentItemChange::record($fresh, $actor, 'published_date', $local, $fresh->published_date?->toDateString(), ContentItemChange::ORIGIN_PLANNER);

        return $fresh;
    }

    /**
     * The new Notion date. A time of day, an end date (moved by the same
     * number of days) and a time zone already on the page are kept -- only
     * the day changes.
     *
     * @param  array<string, mixed>|null  $current
     * @return array<string, mixed>
     */
    private function dateValue(?array $current, string $to): array
    {
        $start = $current['start'] ?? null;
        $value = ['start' => $start && strlen($start) > 10 ? $to.substr($start, 10) : $to];

        if (! empty($current['end']) && $start) {
            $days = (int) Carbon::parse(substr($start, 0, 10))->diffInDays(Carbon::parse($to), false);
            $end = Carbon::parse(substr($current['end'], 0, 10))->addDays($days)->toDateString();
            $value['end'] = $end.substr($current['end'], 10);
        }
        if (! empty($current['time_zone'])) {
            $value['time_zone'] = $current['time_zone'];
        }

        return $value;
    }

    private function reason(Throwable $e): string
    {
        if ($e instanceof RequestException) {
            $status = $e->response->status();
            $message = $e->response->json('message');

            return match (true) {
                $status === 404 => 'Notion cannot find this page any more (deleted, or no longer shared with the integration).',
                $status === 429 => 'Notion is busy (rate limited). Try again in a few seconds.',
                $status >= 500 => 'Notion is having trouble right now. Try again in a minute.',
                default => 'Notion refused the change'.($message ? ': '.$message : '.'),
            };
        }

        return 'Could not reach Notion. Check the connection and try again.';
    }

    private function say(?string $date): string
    {
        return $date ? Carbon::parse($date)->format('D j M') : 'no date';
    }
}
