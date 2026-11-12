<?php

namespace App\Http\Controllers\Api\Planner;

use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use App\Services\Ai\CaptionWriter;
use App\Services\Ai\GeminiBudget;
use App\Services\Ai\GeminiBudgetExceeded;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Captions for a planner video: write three options with Gemini (inside the
 * monthly limit), and keep the one chosen. Needs Chakra Planner → Edit.
 */
class PlannerCaptionController extends Controller
{
    public function write(Request $request, int $item, CaptionWriter $writer): JsonResponse
    {
        if ($denied = $this->denied($request)) {
            return $denied;
        }

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
            'language' => ['nullable', Rule::in(array_keys(CaptionWriter::LANGUAGES))],
            'fresh' => ['nullable', 'boolean'],
        ]);
        $found = ContentItem::query()->findOrFail($item);

        try {
            $caption = $writer->write($found, $request->user(), $data['note'] ?? null, $data['language'] ?? 'english', (bool) ($data['fresh'] ?? false));
        } catch (GeminiBudgetExceeded $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'budget'], 429);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'failed'], 502);
        }

        return response()->json([
            'options' => $caption->options,
            'reused' => ! $caption->wasRecentlyCreated,
            'cost_usd' => $caption->wasRecentlyCreated ? $caption->cost_usd : 0,
            'budget' => self::budget(),
        ]);
    }

    /** Keep a caption (chosen, edited, or cleared). */
    public function save(Request $request, int $item): JsonResponse
    {
        if ($denied = $this->denied($request)) {
            return $denied;
        }

        $data = $request->validate(['caption' => ['present', 'nullable', 'string', 'max:2200']]);
        $found = ContentItem::query()->findOrFail($item);
        $text = filled($data['caption']) ? trim($data['caption']) : null;

        $max = (int) config('ai.captions.max_hashtags', 5);
        if ($text && CaptionWriter::hashtagCount($text) > $max) {
            return response()->json(['message' => "A caption can have at most {$max} hashtags.", 'errors' => ['caption' => ["At most {$max} hashtags."]]], 422);
        }

        $found->forceFill([
            'caption' => $text,
            'caption_at' => $text ? now() : null,
            'caption_by_id' => $text ? $request->user()->id : null,
        ])->save();

        return response()->json([
            'caption' => $found->caption,
            'hashtags' => $text ? CaptionWriter::hashtagCount($text) : 0,
            'message' => $text ? 'Caption saved.' : 'Caption cleared.',
        ]);
    }

    /** @return array{spent: float, cap: float, left: float} */
    public static function budget(): array
    {
        $s = GeminiBudget::summary();

        return ['spent' => round($s['spent'], 4), 'cap' => $s['cap'], 'left' => round($s['left'], 4), 'blocked' => $s['blocked']];
    }

    private function denied(Request $request): ?JsonResponse
    {
        return $request->user()->can('planner.edit') ? null : response()->json([
            'message' => 'Writing captions needs Chakra Planner → Edit. Ask an admin.', 'code' => 'no_edit',
        ], 403);
    }
}
