<?php

namespace App\Services\Planner;

use App\Models\Script;
use App\Models\Shoot;
use App\Models\ShootEvent;
use App\Models\ShootVideo;
use App\Support\FootageFolder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One shoot as Chakra Planner's card offload needs it: its window, where
 * the client's footage goes, and every take the crew logged, from all three
 * places a take can be on record, merged into one list:
 *
 * 1. shoot_videos -- filed on the runner. The main source.
 * 2. shoot_events of type video whose video row is gone (deleted, or from
 *    before videos carried a video_id). Same moment, different record.
 * 3. scripts.shot_at -- a script of this client (or linked to this shoot)
 *    marked shot during the shoot with no video of it filed anywhere.
 *    A shot_at at exactly midnight is a day, not a time (VideoLinker
 *    stamps those), so it says nothing about where a clip falls and is left
 *    out.
 *
 * logged_at is when it went on record: the crew log a take 2-3 minutes
 * after the camera stops, which is what the Planner's matching relies on.
 */
class PlannerShootTakes
{
    /** Scripts marked shot this long after the wrap still count: the log trails the camera. */
    public const AFTER_WRAP_MINUTES = 30;

    /** @return array<string, mixed> */
    public static function shoot(Shoot $shoot): array
    {
        $client = $shoot->client;
        $date = ($shoot->started_at ?? $shoot->starts_at)->copy();

        return [
            'id' => $shoot->id,
            'title' => $shoot->title,
            'status' => $shoot->status,
            'date' => $shoot->starts_at?->toDateString(),
            'starts_at' => $shoot->starts_at?->toIso8601String(),
            'started_at' => $shoot->started_at?->toIso8601String(),
            'wrapped_at' => $shoot->finished_at?->toIso8601String(),
            'footage_filed_at' => $shoot->footage_filed_at?->toIso8601String(),
            'partner_shoot_ids' => $shoot->together_key ? $shoot->partners()->pluck('id')->all() : [],
            'client' => $client ? [
                'id' => $client->id,
                'name' => $client->name,
                'client_label' => $shoot->clientLabel(),
                'drive_root' => filled($client->drive_root) ? $client->drive_root : null,
                'folder_prefix' => FootageFolder::prefix($client),
                'pending_path_template' => FootageFolder::template($client),
                'pending_path' => FootageFolder::pendingPath(FootageFolder::template($client), $date),
            ] : null,
            'takes' => self::takes($shoot)->values()->all(),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public static function takes(Shoot $shoot): Collection
    {
        $videos = $shoot->videos()->get();

        $takes = $videos->map(fn (ShootVideo $v) => [
            'id' => 'sv:'.$v->id,
            'name' => $v->name,
            'logged_at' => $v->created_at,
            'started_at' => $v->started_at?->toIso8601String(),
            'source' => 'shoot_video',
            'shoot_video_id' => $v->id,
            'script_id' => $v->script_id,
            'footage_copied_at' => $v->footage_copied_at?->toIso8601String(),
        ]);

        $videoIds = $videos->pluck('id')->all();
        $events = ShootEvent::query()->where('shoot_id', $shoot->id)->where('type', ShootEvent::VIDEO)->orderBy('created_at')->get();
        foreach ($events as $e) {
            $videoId = $e->meta['video_id'] ?? null;
            if ($videoId !== null && in_array((int) $videoId, $videoIds, true)) {
                continue;
            }
            // Without a video_id: the same name logged within a minute is the same take.
            if ($videoId === null && $videos->contains(fn (ShootVideo $v) => $v->name === $e->note && abs($v->created_at->diffInSeconds($e->created_at, true)) <= 60)) {
                continue;
            }
            $takes->push([
                'id' => 'ev:'.$e->id,
                'name' => (string) $e->note,
                'logged_at' => $e->created_at,
                'started_at' => null,
                'source' => 'shoot_event',
                'shoot_video_id' => null,
                'script_id' => null,
                'footage_copied_at' => null,
            ]);
        }

        foreach (self::scripts($shoot) as $script) {
            $takes->push([
                'id' => 'script:'.$script->id,
                'name' => $script->title,
                'logged_at' => $script->shot_at,
                'started_at' => null,
                'source' => 'script',
                'shoot_video_id' => null,
                'script_id' => $script->id,
                'footage_copied_at' => null,
            ]);
        }

        return $takes->sortBy(fn (array $t) => [$t['logged_at']->getTimestamp(), $t['id']])->values()
            ->map(fn (array $t) => ['logged_at' => $t['logged_at']->toIso8601String()] + $t);
    }

    /** @return Collection<int, Script> */
    private static function scripts(Shoot $shoot): Collection
    {
        [$from, $to] = self::window($shoot);
        if (! $from) {
            return collect();
        }

        $linked = $shoot->scripts()->pluck('scripts.id');

        return Script::query()
            ->where(fn ($q) => $q->whereIn('id', $linked)->when($shoot->client_id, fn ($q) => $q->orWhere('client_id', $shoot->client_id)))
            ->whereBetween('shot_at', [$from, $to])
            ->whereDoesntHave('shootVideos')
            ->get(['id', 'title', 'shot_at'])
            ->reject(fn (Script $s) => $s->shot_at->format('H:i:s') === '00:00:00');
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    private static function window(Shoot $shoot): array
    {
        if ($shoot->started_at) {
            return [$shoot->started_at, ($shoot->finished_at ?? now())->copy()->addMinutes(self::AFTER_WRAP_MINUTES)];
        }

        return [null, null];
    }
}
