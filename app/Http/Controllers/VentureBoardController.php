<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\VentureAlertSetting;
use App\Models\VentureWatch;
use App\Services\Notion\NotionSyncRunner;
use App\Support\VentureBoard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

use function Illuminate\Support\defer;

/**
 * The dashboard's "My ventures" board: its numbers (polled by the page),
 * which ventures it shows, how its owner is notified, and a refresh from
 * Notion that answers at once.
 */
class VentureBoardController extends Controller
{
    public function data(Request $request): JsonResponse
    {
        return response()->json(VentureBoard::forUser($request->user()) + [
            'refreshing' => NotionSyncRunner::isRunning(),
        ]);
    }

    /** Every venture, for the picker. */
    public function ventures(Request $request): JsonResponse
    {
        return response()->json([
            'ventures' => VentureBoard::all(),
            'chosen' => VentureBoard::chosen($request->user()),
            'settings' => VentureAlertSetting::for($request->user())->toPanel(),
        ]);
    }

    /** Save the chosen ventures, in the order given. */
    public function choose(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ventures' => ['present', 'array', 'max:60'],
            'ventures.*' => ['string', 'max:255'],
        ]);
        $user = $request->user();
        $wanted = array_values(array_unique($data['ventures']));

        DB::transaction(function () use ($user, $wanted) {
            VentureWatch::query()->where('user_id', $user->id)->whereNotIn('venture', $wanted ?: [''])->delete();
            foreach ($wanted as $i => $venture) {
                VentureWatch::query()->updateOrCreate(['user_id' => $user->id, 'venture' => $venture], ['sort_order' => $i]);
            }
        });

        return $this->data($request);
    }

    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'daily_summary' => ['required', 'boolean'],
            'summary_at' => ['required', 'date_format:H:i'],
            'low_stock' => ['required', 'integer', 'min:1', 'max:50'],
            'notify_low' => ['required', 'boolean'],
            'notify_ready' => ['required', 'boolean'],
            'notify_published' => ['required', 'boolean'],
            'notify_shot' => ['required', 'boolean'],
            'whatsapp' => ['required', 'boolean'],
        ]);

        $settings = VentureAlertSetting::for($request->user());
        $settings->update($data);

        return response()->json(['settings' => $settings->fresh()->toPanel(), 'message' => 'Saved.']);
    }

    /** Pull from Notion after answering; the page polls data() for the result. */
    public function refresh(): JsonResponse
    {
        defer(fn () => rescue(fn () => NotionSyncRunner::syncIfOlderThan(60), null, true));

        return response()->json(['message' => 'Refreshing from Notion…', 'last_synced_at' => NotionSyncRunner::lastSyncedAt()?->toIso8601String()], 202);
    }

    /** Send a test notification to yourself, through every channel you chose. */
    public function test(Request $request): JsonResponse
    {
        $request->user()->notify(new \App\Notifications\VentureUpdate(
            'Venture alerts are on',
            'This is how venture news will reach you: on your phone, in the app inbox and on the bell here.',
            'venture-test',
        ));

        return response()->json(['message' => 'Sent. Check the bell, and your phone if the app is installed.']);
    }
}
