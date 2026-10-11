<?php

namespace App\Http\Controllers\Api\Planner;

use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use App\Services\Planner\NotionWriteFailed;
use App\Services\Planner\PlannerConflict;
use App\Services\Planner\PlannerItems;
use App\Services\Planner\PlannerSchema;
use App\Services\Planner\PublishDateMove;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The planner items: the four Notion content planners (Reel, Post, Story,
 * YouTube) as the portal holds them.
 *
 * Reading is everyone with Chakra Planner access. Changing is `planner.edit`
 * and, for now, only the publish date -- written to Notion first, see
 * PublishDateMove.
 */
class PlannerItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Taken before the query, so a change landing mid-request is picked
        // up by the app's next ?updated_since= rather than lost between them.
        $serverTime = now();

        $data = $request->validate([
            'source' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:500'],
            'client' => ['nullable', 'string', 'max:255'],
            'assigned_user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'undated' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
            'updated_since' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
        $list = fn (?string $csv) => array_values(array_filter(array_map('trim', explode(',', (string) $csv)), 'strlen'));

        $items = $this->items()
            ->with(PlannerItems::RELATIONS)
            // A delta must also say what disappeared from Notion; a full list just leaves it out.
            ->when(isset($data['updated_since']),
                fn (Builder $q) => $q->where('updated_at', '>=', Carbon::parse($data['updated_since'])->setTimezone(config('app.timezone'))),
                fn (Builder $q) => $q->visible())
            ->when($list($data['source'] ?? null), fn (Builder $q, array $v) => $q->whereIn('source', $v))
            ->when($list($data['status'] ?? null), fn (Builder $q, array $v) => $q->whereIn('status', $v))
            ->when($data['client'] ?? null, fn (Builder $q, string $v) => $q->where('venture', $v))
            ->when($data['assigned_user_id'] ?? null, fn (Builder $q, $v) => $q->where('assigned_user_id', (int) $v))
            ->when($data['from'] ?? null, fn (Builder $q, string $v) => $q->whereDate('published_date', '>=', $v))
            ->when($data['to'] ?? null, fn (Builder $q, string $v) => $q->whereDate('published_date', '<=', $v))
            ->when($request->boolean('undated'), fn (Builder $q) => $q->whereNull('published_date'))
            ->when($data['q'] ?? null, fn (Builder $q, string $v) => $q->where('title', 'like', '%'.addcslashes($v, '%_\\').'%'))
            ->orderByRaw('published_date is null')->orderBy('published_date')->orderBy('id')
            ->paginate((int) ($data['per_page'] ?? 500));

        return response()->json([
            'data' => $items->getCollection()->map(fn (ContentItem $i) => PlannerItems::row($i))->values(),
            'meta' => [
                'page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
                'server_time' => $serverTime->toIso8601String(),
            ],
        ]);
    }

    public function show(int $item): JsonResponse
    {
        $found = $this->items()->with(PlannerItems::RELATIONS)->findOrFail($item);

        return response()->json(['item' => PlannerItems::detail($found)]);
    }

    public function update(Request $request, int $item, PublishDateMove $move): JsonResponse
    {
        if (! $request->user()->can('planner.edit')) {
            return response()->json(['message' => 'You can view the planner but not change it. Ask an admin for Chakra Planner → Edit.', 'code' => 'no_edit'], 403);
        }

        $found = $this->items()->findOrFail($item);
        $data = $request->validate([
            'published_date' => ['present', 'nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d'],
        ]);

        try {
            $fresh = $move->move($found, $data['published_date'], $data['from'] ?? null, $request->exists('from'), $request->user());
        } catch (PlannerConflict $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'conflict',
                'item' => PlannerItems::detail($e->item->fresh()->load(PlannerItems::RELATIONS)),
            ], 409);
        } catch (NotionWriteFailed $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'notion_failed'], 502);
        }

        return response()->json(['item' => PlannerItems::detail($fresh->fresh()->load(PlannerItems::RELATIONS))]);
    }

    private function items(): Builder
    {
        return ContentItem::query()->whereIn('source', PlannerSchema::sources());
    }
}
