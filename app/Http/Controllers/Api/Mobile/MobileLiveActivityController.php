<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\LiveActivityToken;
use App\Services\Push\ApnsClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** The iPhone app's Live Activity push tokens (see docs/chakra-mobile/API.md → Live Activities). */
class MobileLiveActivityController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in([LiveActivityToken::TYPE_START, LiveActivityToken::TYPE_UPDATE])],
            'activity' => ['required', Rule::in(LiveActivityToken::ACTIVITIES)],
            'token' => ['required', 'string', 'regex:/^[0-9a-fA-F]{32,400}$/'],
            'activity_id' => ['required_if:type,update', 'nullable', 'string', 'max:120'],
            'ref' => ['nullable', 'array'],
            'ref.shoot_id' => ['nullable', 'integer', 'exists:shoots,id'],
            'environment' => ['nullable', Rule::in([ApnsClient::SANDBOX, ApnsClient::PRODUCTION])],
        ]);
        $user = $request->user();
        $token = strtolower($data['token']);

        // One push-to-start token per device+activity; one update token per running activity.
        if ($data['type'] === LiveActivityToken::TYPE_UPDATE) {
            LiveActivityToken::query()->where('user_id', $user->id)->where('activity_id', $data['activity_id'])->delete();
        }

        $row = LiveActivityToken::query()->updateOrCreate(['token_hash' => hash('sha256', $token)], [
            'user_id' => $user->id,
            'type' => $data['type'],
            'activity' => $data['activity'],
            'token' => $token,
            'activity_id' => $data['activity_id'] ?? null,
            'shoot_id' => $data['ref']['shoot_id'] ?? null,
            'environment' => $data['environment'] ?? ApnsClient::PRODUCTION,
        ]);
        $row->forceFill(['ended_at' => null, 'failure_reason' => null])->save();

        return response()->json(['id' => $row->id, 'apns_configured' => ApnsClient::make()->isConfigured()], 201);
    }

    /** {token} for one, or {activity_id} when an activity ended on the phone. */
    public function destroy(Request $request): Response
    {
        $data = $request->validate([
            'token' => ['required_without:activity_id', 'nullable', 'string', 'max:400'],
            'activity_id' => ['nullable', 'string', 'max:120'],
        ]);
        LiveActivityToken::query()->where('user_id', $request->user()->id)
            ->where(fn ($q) => filled($data['token'] ?? null)
                ? $q->where('token_hash', hash('sha256', strtolower($data['token'])))
                : $q->where('activity_id', $data['activity_id']))
            ->delete();

        return response()->noContent();
    }
}
