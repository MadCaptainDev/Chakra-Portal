<?php

namespace App\Http\Controllers\Api\Planner;

use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use App\Models\User;
use App\Services\Notion\NotionSyncRunner;
use App\Services\Planner\PlannerRealtime;
use App\Services\Planner\PlannerSchema;
use App\Support\ContentDashboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Everything around the items: the vocabulary the app renders with, the
 * Refresh button, and the realtime sign-in.
 */
class PlannerAppController extends Controller
{
    public function meta(): JsonResponse
    {
        $visible = ContentItem::query()->visible()->whereIn('source', PlannerSchema::sources());
        $counts = (clone $visible)->selectRaw('source, count(*) as n')->groupBy('source')->pluck('n', 'source');

        return response()->json([
            'sources' => collect(PlannerSchema::sources())->map(fn (string $s) => [
                'key' => $s,
                'label' => config("notion.databases.{$s}.label", ucfirst($s)),
                'count' => (int) ($counts[$s] ?? 0),
            ])->values(),
            'statuses' => PlannerSchema::statuses(),
            // Per planner: which fields can be edited, their Notion type and select options.
            'fields' => PlannerSchema::fields(),
            'can_edit' => (bool) request()->user()?->can('planner.edit'),
            'status_groups' => ContentDashboard::STATUS_GROUPS,
            'colors' => PlannerSchema::COLORS,
            'clients' => (clone $visible)->whereNotNull('venture')
                ->selectRaw('venture as name, count(*) as count')->groupBy('venture')->orderBy('venture')
                ->get()->map(fn ($r) => ['name' => $r->name, 'count' => (int) $r->count] + ['id' => \App\Support\PlannerVisuals::client($r->name)['id'] ?? null, 'logo_url' => \App\Support\PlannerVisuals::client($r->name)['logo_url'] ?? null])->values(),
            'people' => User::query()->where('role', '!=', User::ROLE_CLIENT)->orderBy('name')->get(['id', 'name', 'avatar_path'])
                ->map(fn (User $u) => \App\Support\PlannerVisuals::person($u))->values(),
            'notion' => [
                'last_synced_at' => NotionSyncRunner::lastSyncedAt()?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Pull the planners from Notion now. Shares NotionSyncRunner's lock and
     * skips if a sync finished in the last minute, so a team pressing
     * Refresh together runs it once. Takes 10-20 seconds when it runs.
     */
    public function refresh(Request $request): JsonResponse
    {
        // background=1: answer at once and sync after the response, so the
        // person is not left watching a button for twenty seconds. The page
        // picks the changes up on its next poll.
        if ($request->boolean('background')) {
            \Illuminate\Support\defer(fn () => rescue(fn () => NotionSyncRunner::syncIfOlderThan(60), null, true));

            return response()->json([
                'refreshed' => null,
                'last_synced_at' => NotionSyncRunner::lastSyncedAt()?->toIso8601String(),
                'message' => 'Refreshing from Notion…',
            ], 202);
        }

        $ran = NotionSyncRunner::syncIfOlderThan(60);

        return response()->json([
            'refreshed' => $ran,
            'last_synced_at' => NotionSyncRunner::lastSyncedAt()?->toIso8601String(),
            'message' => $ran ? 'Refreshed from Notion.' : 'Already up to date -- refreshed in the last minute, or a refresh is running.',
        ]);
    }

    /** A signed Ably TokenRequest; point the Ably SDK's authUrl here. */
    public function realtimeToken(Request $request): JsonResponse
    {
        $tokenRequest = PlannerRealtime::tokenRequest($request->user());

        if ($tokenRequest === null) {
            return response()->json(['message' => 'Live updates are switched off. Poll /api/planner/items?updated_since= instead.', 'code' => 'realtime_off'], 404);
        }

        return response()->json($tokenRequest);
    }
}
