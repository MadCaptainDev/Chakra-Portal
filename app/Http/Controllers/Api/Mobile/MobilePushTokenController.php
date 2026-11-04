<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\PushToken;
use App\Services\Push\PushSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The phone's Firebase Cloud Messaging token (firebase_messaging's
 * getToken()). Only useful once the iOS build is signed by a paid Apple
 * Developer account with an APNs key uploaded to Firebase -- before that
 * the app relies on the inbox instead (see docs/chakra-mobile/README.md).
 */
class MobilePushTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'min:20', 'max:4096'],
            'platform' => ['required', 'string', 'in:ios,android'],
            'device_name' => ['nullable', 'string', 'max:120'],
            // fcm = a Firebase token; apns = the raw APNs device token (hex), sent straight to Apple.
            'kind' => ['nullable', 'in:fcm,apns'],
            'environment' => ['nullable', 'in:sandbox,production'],
        ]);

        $token = PushToken::registerNative($request->user(), $data['token'], $data['platform'], $data['device_name'] ?? null);
        $apns = ($data['kind'] ?? 'fcm') === 'apns';
        if ($apns) {
            $token->forceFill(['device_kind' => PushToken::KIND_APNS, 'environment' => $data['environment'] ?? 'production'])->save();
        }

        return response()->json([
            'id' => $token->id,
            'push_configured' => $apns ? \App\Services\Push\ApnsClient::make()->isConfigured() : PushSender::make()->canSend(),
        ], 201);
    }

    public function destroy(Request $request): Response
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:4096']]);
        $request->user()->pushTokens()->where('token_hash', hash('sha256', $data['token']))->delete();

        return response()->noContent();
    }
}
