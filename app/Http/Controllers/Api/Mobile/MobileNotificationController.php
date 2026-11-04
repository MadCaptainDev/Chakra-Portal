<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The notification inbox. Newest first, paged by id (?before=), and pollable
 * cheaply (?after= the newest id the app has; an empty list means nothing
 * new). Without remote push the app polls ?after= on open, on resume and on
 * a background fetch, and shows a local notification for each new row.
 */
class MobileNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'after' => ['nullable', 'integer', 'min:0'],
            'before' => ['nullable', 'integer', 'min:1'],
            'unread' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $limit = (int) ($data['limit'] ?? 30);
        $userId = $request->user()->id;

        $query = AppNotification::query()->where('user_id', $userId)->orderByDesc('id');
        if (isset($data['after'])) {
            $query->where('id', '>', $data['after']);
        }
        if (isset($data['before'])) {
            $query->where('id', '<', $data['before']);
        }
        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        $rows = $query->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return response()->json([
            'data' => $rows->map->toApi()->values(),
            'unread' => AppNotification::query()->where('user_id', $userId)->whereNull('read_at')->count(),
            'latest_id' => (int) AppNotification::query()->where('user_id', $userId)->max('id'),
            'next_before' => $more ? $rows->last()?->id : null,
        ]);
    }

    /** Mark some (ids: [..]) or all (no ids) read. */
    public function read(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['nullable', 'array', 'max:200'],
            'ids.*' => ['integer'],
        ]);
        $query = AppNotification::query()->where('user_id', $request->user()->id)->whereNull('read_at');
        if (! empty($data['ids'])) {
            $query->whereIn('id', $data['ids']);
        }
        $marked = $query->update(['read_at' => now()]);

        return response()->json([
            'marked' => $marked,
            'unread' => AppNotification::query()->where('user_id', $request->user()->id)->whereNull('read_at')->count(),
        ]);
    }
}
