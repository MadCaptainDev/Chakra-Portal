<?php

namespace App\Http\Controllers\Api\Planner;

use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use App\Models\Script;
use App\Models\ScriptSection;
use App\Services\Planner\PlannerSchema;
use Illuminate\Http\JsonResponse;

/**
 * The portal script for one planner item, for the app's Script window
 * (GET api/planner/items/{id}/script). Read-only, same token and the same
 * Chakra Planner "view" ability as GET items/{id}.
 *
 * 404 with code "no_script" when the video exists but has no script, so
 * the app can tell "none written" from a missing item (plain 404).
 */
class PlannerScriptController extends Controller
{
    public function show(int $item): JsonResponse
    {
        abort_unless(ContentItem::query()->whereIn('source', PlannerSchema::sources())->whereKey($item)->exists(), 404);

        $script = Script::query()->where('content_item_id', $item)->orderByDesc('updated_at')->orderByDesc('id')->first();
        if (! $script) {
            return response()->json(['message' => 'No script written for this video yet.', 'code' => 'no_script'], 404);
        }

        return response()->json(['script' => [
            'id' => $script->id,
            'title' => $script->title,
            'status' => $script->status,
            'updated_at' => $script->updated_at?->toIso8601String(),
            'url' => route('scripts.show', $script->id),
            // Every section, in order; the stored HTML as is (the app keeps formatting tags only).
            'sections' => $script->sections->map(fn (ScriptSection $s) => ['heading' => $s->heading, 'body' => (string) $s->body])->values(),
        ]]);
    }
}
