<?php

namespace App\Support;

use App\Models\ContentItem;
use App\Models\ReviewSetting;
use App\Models\ReviewVideo;
use App\Models\Todo;
use Illuminate\Support\Str;

/**
 * "Every approver must OK the cut before it is Video ready" -- one answer
 * for My Editing's Video ready and the planner's status change, so neither
 * can go round the other. Switched off in Review settings.
 */
class ReviewGate
{
    /** Why this editing card cannot be made ready, or null if it can. */
    public static function blocksReady(Todo $card): ?string
    {
        $settings = ReviewSetting::current();
        if (! config('reviews.enforce', true) || ! $settings->require_for_ready) {
            return null;
        }

        $latest = ReviewVideo::query()->where('todo_id', ($card->parent ?? $card)->id)->latest('version')->first();

        if (! $latest) {
            return 'Upload the cut for review first. It can be made ready once '.self::who($settings).' approved it.';
        }
        if ($latest->status === ReviewVideo::APPROVED) {
            return null;
        }
        if ($latest->status === ReviewVideo::CHANGES) {
            return 'Version '.$latest->version.' was sent back. Upload the fixed version for review.';
        }

        $so = $latest->signOff();
        $waiting = collect($so['required'])->where('approved', false)->pluck('name')->map(fn ($n) => Str::before($n, ' '));

        return 'Waiting for approval'.($waiting->isNotEmpty() ? ' from '.$waiting->implode(' and ') : '').' ('.$so['done'].' of '.max(1, $so['needed']).' done).';
    }

    /** The same, for a planner video moving to "Video Ready". */
    public static function blocksPlannerReady(ContentItem $item): ?string
    {
        $card = Todo::query()->where('content_item_id', $item->id)->whereNull('parent_id')->latest('id')->first();

        // A planner video with no editing card is outside the review flow.
        return $card ? self::blocksReady($card) : null;
    }

    private static function who(ReviewSetting $settings): string
    {
        $names = $settings->approvers()->pluck('name')->map(fn ($n) => Str::before($n, ' '));

        return $names->isEmpty() ? 'a reviewer has' : $names->implode(' and ').' have';
    }
}
