<?php

namespace App\Support;

use App\Models\Todo;
use App\Models\User;
use App\Services\Planner\PlannerPropertyWrite;
use App\Services\Planner\PublishDateMove;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What happens to a video in the portal is written to its Notion planner
 * row, so nobody marks it twice: Ready → "Video Ready", Posted → "Published"
 * (dated the day it went out), Delivered → "Delivered", a post undone → back
 * to "Video Ready". After the response, so a slow Notion never holds the
 * page; a failure is logged and the next sync shows what Notion has.
 *
 * Cards with no planner row (typed in by hand) have nothing to update.
 */
class PlannerStatusPush
{
    public static function after(Todo $todo, string $status, User $by, bool $datedToday = false): void
    {
        $item = $todo->parent_id ? null : $todo->contentItem;
        if (! $item || $item->notion_missing_since) {
            return;
        }

        \Illuminate\Support\defer(fn () => self::now($item, $status, $by, $datedToday));
    }

    /** The write itself (tests call it directly). */
    public static function now(\App\Models\ContentItem $item, string $status, User $by, bool $datedToday = false): void
    {
        try {
            if ($item->status !== $status) {
                app(PlannerPropertyWrite::class)->update($item, ['status' => $status], [], $by);
            }
            // It went out today: that is its publishing date now -- a fact, not a move.
            if ($datedToday && $item->fresh()->published_date?->toDateString() !== today()->toDateString()) {
                app(PublishDateMove::class)->move($item->fresh(), today()->toDateString(), null, false, $by);
            }
        } catch (Throwable $e) {
            Log::warning('The planner row could not be updated from the editing board.', ['content_item_id' => $item->id, 'status' => $status, 'error' => $e->getMessage()]);
        }
    }
}
