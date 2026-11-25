<?php

namespace App\Http\Controllers\Api\Planner;

use App\Http\Controllers\Controller;
use App\Models\Camera;
use App\Models\Shoot;
use App\Models\ShootEvent;
use App\Models\ShootVideo;
use App\Services\Planner\PlannerShootTakes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Card offload: Chakra Planner reads a camera card, matches its clips to
 * the takes the crew logged, copies them into the client's pending folder
 * and reports back. Documented in docs/chakra-planner/API.md ("Card
 * offload"); keep the two in step.
 *
 * Open to every staff account with a Planner token, not only planners:
 * whoever is holding the card after a shoot is often crew.
 */
class PlannerOffloadController extends Controller
{
    /** Longest from..to a single call may ask for. */
    public const MAX_DAYS = 62;

    public function shoots(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', 'string', 'max:100'],
        ]);

        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : today()->subDays(2);
        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : today()->endOfDay();
        if ($from->diffInDays($to) > self::MAX_DAYS) {
            return response()->json(['message' => 'Ask for at most '.self::MAX_DAYS.' days at a time.', 'code' => 'range_too_long'], 422);
        }

        $statuses = array_values(array_filter(array_map('trim', explode(',', (string) ($data['status'] ?? '')))));
        if ($unknown = array_diff($statuses, array_keys(Shoot::STATUSES))) {
            return response()->json(['message' => 'Unknown status '.implode(', ', $unknown).'.', 'code' => 'bad_status'], 422);
        }

        $shoots = Shoot::query()
            ->with('client')
            ->where(fn ($q) => $q->whereBetween('starts_at', [$from, $to])->orWhereBetween('started_at', [$from, $to]))
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses), fn ($q) => $q->where('status', '!=', Shoot::STATUS_CANCELLED))
            ->orderByRaw('COALESCE(started_at, starts_at)')->orderBy('id')
            ->get();

        return response()->json([
            'data' => $shoots->map(fn (Shoot $s) => PlannerShootTakes::shoot($s))->values(),
            'cameras' => Camera::query()->orderBy('serial')->get()->map->toPlanner()->values(),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function footageCopied(Request $request, ShootVideo $shootVideo): JsonResponse
    {
        $data = $request->validate([
            'clip_count' => ['required', 'integer', 'min:0', 'max:100000'],
            'bytes' => ['required', 'integer', 'min:0'],
            'folder' => ['required', 'string', 'max:1000'],
            'checksum' => ['nullable', 'array'],
            'checksum.algo' => ['required_with:checksum', 'string', 'max:40'],
            'checksum.digest' => ['required_with:checksum', 'string', 'max:200'],
            'checksum.files' => ['nullable', 'integer', 'min:0'],
            'camera_serials' => ['nullable', 'array', 'max:20'],
            'camera_serials.*' => ['string', 'max:64'],
            'copied_at' => ['nullable', 'date'],
        ]);

        $shoot = $shootVideo->shoot;
        $first = false;

        DB::transaction(function () use ($shootVideo, $data, &$first) {
            $video = ShootVideo::query()->lockForUpdate()->find($shootVideo->id);
            $first = $video->footage_copied_at === null;
            if ($first) {
                $at = isset($data['copied_at']) ? Carbon::parse($data['copied_at']) : now();
                $video->forceFill(['footage_copied_at' => $at->isFuture() ? now() : $at])->save();
            }
        });
        $shootVideo->refresh();

        ShootEvent::record(
            $shoot, $request->user(), ShootEvent::FOOTAGE_COPIED,
            $shootVideo->name.': '.$data['clip_count'].' '.str('clip')->plural($data['clip_count']).', '.self::size((int) $data['bytes']),
            array_filter([
                'video_id' => $shootVideo->id,
                'clips' => (int) $data['clip_count'],
                'bytes' => (int) $data['bytes'],
                'folder' => $data['folder'],
                'checksum' => $data['checksum'] ?? null,
                'camera_serials' => $data['camera_serials'] ?? null,
                'again' => $first ? null : true,
            ], fn ($v) => $v !== null),
        );

        return response()->json([
            'shoot_video_id' => $shootVideo->id,
            'shoot_id' => $shoot->id,
            'footage_copied_at' => $shootVideo->footage_copied_at->toIso8601String(),
            'already_copied' => ! $first,
            'shoot_all_copied' => ! $shoot->videos()->whereNull('footage_copied_at')->exists(),
        ]);
    }

    public function camera(Request $request, string $serial): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            // A camera a whole day or more out is set wrong, not drifting; still allowed, within a year.
            'clock_offset_seconds' => ['nullable', 'integer', 'between:-31536000,31536000'],
        ]);

        $camera = Camera::firstOrNew(['serial' => $serial]);
        if (array_key_exists('name', $data)) {
            $camera->name = $data['name'];
        }
        if (array_key_exists('clock_offset_seconds', $data) && $data['clock_offset_seconds'] !== null) {
            $camera->clock_offset_seconds = $data['clock_offset_seconds'];
        }
        $camera->clock_offset_seconds ??= 0;
        $camera->save();

        return response()->json(['data' => $camera->toPlanner()], $camera->wasRecentlyCreated ? 201 : 200);
    }

    private static function size(int $bytes): string
    {
        return $bytes >= 1_000_000_000 ? round($bytes / 1_000_000_000, 1).' GB' : round($bytes / 1_000_000).' MB';
    }
}
