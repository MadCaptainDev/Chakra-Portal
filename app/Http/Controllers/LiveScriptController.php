<?php

namespace App\Http\Controllers;

use App\Models\Script;
use App\Models\ScriptSection;
use App\Support\Html;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * One script on a no-login live link (set/{token}), for the shoot: the
 * director, the talent or the client reads it on their phone and fixes a
 * line, and everyone else holding the link sees it within seconds.
 *
 * The token is the only credential -- same rule as brief/{token} and
 * p/{token}: an unknown or revoked token is a 404. Writes are CSRF-exempt
 * (a phone left open on set outlives its session) and throttled.
 *
 * Only words change here: section headings and text, and adding a section.
 * Deleting and reordering stay in the portal, so a slip of a thumb on set
 * cannot throw a section away.
 */
class LiveScriptController extends Controller
{
    public function show(string $token): View
    {
        $script = $this->script($token);

        return view('scripts.live', ['script' => $script, 'state' => $this->state($script)]);
    }

    /** Polled every few seconds by every phone holding the link. */
    public function poll(string $token): JsonResponse
    {
        return response()->json($this->state($this->script($token)));
    }

    /** Autosave one section, with the same version check as the portal's editor. */
    public function update(Request $request, string $token, int $section): JsonResponse
    {
        $script = $this->script($token);
        $validated = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'heading' => ['required', 'string', 'max:160'],
            'body' => ['nullable', 'string', 'max:200000'],
            'name' => ['nullable', 'string', 'max:80'],
        ]);
        $model = $script->sections()->findOrFail($section);

        $affected = ScriptSection::whereKey($model->getKey())
            ->where('version', $validated['version'])
            ->update([
                'heading' => $validated['heading'],
                'body' => Html::sanitise($validated['body'] ?? null),
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            $model->refresh();

            return response()->json([
                'conflict' => true,
                'message' => ($script->lastEditorName() ?? 'Someone else').' changed this section while you were typing.',
                'current' => ['heading' => $model->heading, 'body' => $model->body, 'version' => $model->version],
            ], 409);
        }

        $script->touchEditedByGuest($validated['name'] ?? null);
        $model->refresh();

        return response()->json(['id' => $model->id, 'version' => $model->version, 'body' => $model->body]);
    }

    public function store(Request $request, string $token): JsonResponse
    {
        $script = $this->script($token);
        $validated = $request->validate([
            'heading' => ['required', 'string', 'max:160'],
            'name' => ['nullable', 'string', 'max:80'],
        ]);

        $section = $script->sections()->make(['heading' => $validated['heading']]);
        $section->position = (int) $script->sections()->max('position') + 1;
        $section->save();
        $script->touchEditedByGuest($validated['name'] ?? null);

        return response()->json($this->section($section->refresh()), 201);
    }

    private function script(string $token): Script
    {
        return Script::query()->where('share_token', $token)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function state(Script $script): array
    {
        $script->load(['sections', 'lastEditedBy:id,name']);

        return [
            'title' => $script->title,
            'edited_by' => $script->lastEditorName(),
            'edited_at' => $script->last_edited_at?->toIso8601String(),
            'sections' => $script->sections->map(fn (ScriptSection $s) => $this->section($s))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function section(ScriptSection $s): array
    {
        return ['id' => $s->id, 'heading' => $s->heading, 'body' => $s->body ?? '', 'version' => (int) ($s->version ?: 1)];
    }
}
