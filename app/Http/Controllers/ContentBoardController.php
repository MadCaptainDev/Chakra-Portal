<?php

namespace App\Http\Controllers;

use App\Models\ContentItem;
use App\Models\NotionShoot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * A read-only board of what Notion says is happening -- Reel content and
 * Shoots, the two sources the studio asked to see this way. Never youtube,
 * post or story: those exist as sources but have no board here.
 *
 * READ-ONLY, by design and by construction. There is no write route on this
 * controller, no drag-and-drop in the view, and nothing here calls Notion at
 * all -- it only ever reads content_items / notion_shoots, which
 * ContentSyncService already populated by its own read-only calls. Changing
 * a status happens in Notion; the next sync is what brings it here.
 */
class ContentBoardController extends Controller
{
    public function index(): View
    {
        $reels = ContentItem::where('source', ContentItem::SOURCE_REEL)
            ->orderByRaw('published_date is null, published_date desc')
            ->get();

        $shoots = NotionShoot::orderByRaw('shoot_date is null, shoot_date desc')->get();

        return view('content-board.index', [
            'reelColumns' => $this->columns($reels, 'status', 'reel'),
            'shootColumns' => $this->columns($shoots, 'status', 'shoot'),
            'reelCount' => $reels->count(),
            'shootCount' => $shoots->count(),
            'lastSynced' => $this->lastSyncedAt(),
        ]);
    }

    /**
     * Group $items by their $field into the columns configured for $source,
     * in that configured order -- PLUS any status value actually present in
     * the data that isn't in the config, appended as a trailing column
     * (including a "No status" bucket for null/blank).
     *
     * Grouping strictly by the configured list would make a card whose
     * status is a brand new Notion option, or is empty, silently vanish --
     * indistinguishable on a planning board from "that work doesn't exist".
     * An unexpected trailing column is a visibly odd surprise instead, which
     * is the safer failure.
     *
     * @return list<array{label: string, color: string, items: \Illuminate\Support\Collection}>
     */
    private function columns(Collection $items, string $field, string $source): array
    {
        $configured = collect(config("notion.boards.{$source}", []));

        /*
         * collect($items->all())->groupBy(...), not $items->groupBy(...)
         * directly: groupBy() on an Eloquent Collection returns an Eloquent
         * Collection whose "items" are themselves group Collections, not
         * models -- and Eloquent\Collection::except() (used below) assumes
         * its items ARE models and calls getKey() on each one, which blows
         * up on a group of groups. Starting from a plain Support Collection
         * keeps the whole grouped structure plain throughout.
         *
         * A blank status (null or '') is grouped under one literal key --
         * PHP array keys coerce null to '' anyway, so grouping by null
         * directly would silently split into two buckets that look
         * identical to a person reading the board.
         */
        $byStatus = collect($items->all())->groupBy(fn ($item) => blank($item->{$field}) ? '' : $item->{$field});

        $columns = $configured->map(function (array $column) use ($byStatus) {
            return [
                'label' => $column['label'] ?? $column['status'],
                'color' => $column['color'] ?? 'gray',
                'items' => $byStatus->get($column['status'], collect()),
            ];
        })->values();

        $known = $configured->pluck('status')->all();

        $extra = $byStatus->except($known)->map(function ($items, $status) {
            return [
                'label' => blank($status) ? 'No status' : $status,
                'color' => 'gray',
                'items' => $items,
            ];
        })->values();

        return $columns->concat($extra)->all();
    }

    private function lastSyncedAt(): ?Carbon
    {
        $values = array_filter([ContentItem::max('synced_at'), NotionShoot::max('synced_at')]);

        return $values === [] ? null : Carbon::parse(max($values));
    }
}
