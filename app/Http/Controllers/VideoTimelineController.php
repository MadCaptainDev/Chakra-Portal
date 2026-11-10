<?php

namespace App\Http\Controllers;

use App\Models\ContentItem;
use App\Models\Todo;
use App\Services\Videos\VideoThumbnail;
use App\Services\Videos\VideoTimeline;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Click a video, see what happened to it": one page per video, reached from
 * Editor Updates (by its editing card) or the planner (by its planner row).
 *
 * Staff only. An admin or a manager sees any video; an editor sees their own
 * cards -- the same line Editor Updates draws.
 */
class VideoTimelineController extends Controller
{
    public function card(Request $request, Todo $todo): View
    {
        $card = $todo->parent ?? $todo;
        $user = $request->user();

        abort_if($user->isClient(), 403);
        abort_unless(
            $user->isAdmin() || $card->user_id === $user->id
                || ($user->managesAnyone() && $user->reports()->whereKey($card->user_id)->exists()),
            403,
        );

        return $this->page(VideoTimeline::forTodo($card));
    }

    public function item(Request $request, ContentItem $item): View
    {
        abort_unless($request->user()->can('planner.view') || $request->user()->isAdmin(), 403);

        return $this->page(VideoTimeline::forItem($item));
    }

    /** @param  array<string, mixed>  $story */
    private function page(array $story): View
    {
        // A published video with an Instagram post gets its picture the first time anyone looks.
        if ($story['video'] && ! $story['video']->thumbnail_path) {
            VideoThumbnail::store($story['video']);
        }

        return view('videos.show', $story);
    }
}
