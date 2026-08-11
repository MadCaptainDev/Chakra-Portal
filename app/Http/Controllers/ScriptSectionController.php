<?php

namespace App\Http\Controllers;

use App\Models\Script;
use App\Models\ScriptSection;
use App\Support\Html;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The editor's own endpoints. Everything here answers JSON, because it is
 * called by the autosave rather than by a form post.
 */
class ScriptSectionController extends Controller
{
    public function store(Request $request, Script $script): JsonResponse
    {
        $validated = $request->validate([
            'heading' => ['required', 'string', 'max:160'],
            'after' => ['nullable', 'integer'],
        ]);

        $section = DB::transaction(function () use ($script, $validated) {
            // Insert after a given block, or at the end. Everything below it
            // shuffles down by one inside the same transaction.
            $position = $validated['after'] !== null
                ? (int) ($script->sections()->whereKey($validated['after'])->value('position') ?? -1) + 1
                : (int) $script->sections()->max('position') + 1;

            $script->sections()->where('position', '>=', $position)->increment('position');

            $section = $script->sections()->make(['heading' => $validated['heading']]);
            $section->position = $position;
            $section->save();

            return $section;
        });

        $script->touchEditedBy($request->user());

        return response()->json($this->payload($section), 201);
    }

    /**
     * Autosave one block.
     *
     * The write is a single conditional UPDATE rather than a read, a check and
     * a save. Checking the version in PHP and then saving is a race: two saves
     * arriving together both read the same version, both decide they are fine,
     * and the slower one silently overwrites the faster. Letting the database
     * match on the version makes the check and the write one atomic step.
     */
    public function update(Request $request, Script $script, ScriptSection $section): JsonResponse
    {
        $validated = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'heading' => ['required', 'string', 'max:160'],
            // Generous, but bounded: DOMDocument on unbounded input is a way
            // to exhaust memory.
            'body' => ['nullable', 'string', 'max:200000'],
        ]);

        $affected = ScriptSection::whereKey($section->getKey())
            ->where('version', $validated['version'])
            ->update([
                'heading' => $validated['heading'],
                // The model mutator does not run on a query-builder update, so
                // the allowlist is applied by hand here. This is the only
                // place that writes a body without going through the model.
                'body' => Html::sanitise($validated['body'] ?? null),
                'version' => DB::raw('version + 1'),
                // A query-builder update does not touch timestamps either.
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            return $this->conflict($section);
        }

        /*
         * Only the section's own version moves. The script's version is for
         * structural change -- add, delete, reorder -- so two writers working
         * on different blocks of the same script never collide.
         */
        $script->touchEditedBy($request->user());

        return response()->json($this->payload($section->refresh(), $script));
    }

    public function destroy(Request $request, Script $script, ScriptSection $section): JsonResponse
    {
        $section->delete();

        // Close the gap so positions stay contiguous.
        $script->sections()->where('position', '>', $section->position)->decrement('position');
        $script->touchEditedBy($request->user());

        return response()->json(['deleted' => true]);
    }

    /**
     * Persist a new order.
     *
     * Ids that do not belong to this script are dropped rather than trusted --
     * the same defence the portfolio's tag handling applies. Anything the
     * client omits keeps its place at the end.
     */
    public function reorder(Request $request, Script $script): JsonResponse
    {
        $validated = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        $own = $script->sections()->pluck('id')->all();
        $ordered = array_values(array_intersect($validated['order'], $own));

        DB::transaction(function () use ($ordered, $script) {
            foreach ($ordered as $position => $id) {
                $script->sections()->whereKey($id)->update([
                    'position' => $position,
                    'updated_at' => now(),
                ]);
            }
        });

        $script->touchEditedBy($request->user());

        return response()->json(['order' => $ordered]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ScriptSection $section, ?Script $script = null): array
    {
        return [
            'id' => $section->id,
            'heading' => $section->heading,
            'body' => $section->body,
            'position' => $section->position,
            'version' => $section->version,
            'savedAt' => now()->toIso8601String(),
            'lastEditedBy' => $script?->lastEditedBy?->name,
        ];
    }

    /** 409 carrying the copy that won, so the editor can offer both. */
    private function conflict(ScriptSection $section): JsonResponse
    {
        $section->refresh()->loadMissing('script.lastEditedBy');

        return response()->json([
            'conflict' => true,
            'message' => ($section->script->lastEditedBy?->name ?? 'Someone else')
                .' saved a newer version of this section.',
            'current' => [
                'heading' => $section->heading,
                'body' => $section->body,
                'version' => $section->version,
            ],
        ], 409);
    }
}
