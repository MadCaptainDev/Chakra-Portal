<?php

namespace App\Http\Controllers\Api\Planner;

use App\Http\Controllers\Controller;
use App\Models\PlannerSession;
use App\Models\PlannerSetting;
use App\Models\User;
use App\Services\Notion\NotionSyncRunner;
use App\Services\Planner\PlannerRealtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Signing Chakra Planner in and out. The app signs in with the person's
 * portal email and password once, keeps the token in the OS keychain, and
 * sends it as a bearer token from then on.
 */
class PlannerAuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['required', 'string', 'max:120'],
            'platform' => ['nullable', 'string', 'max:40'],
            'app_version' => ['nullable', 'string', 'max:40'],
        ]);

        $key = 'planner-login:'.Str::lower($data['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return response()->json([
                'message' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
                'code' => 'throttled',
            ], 429);
        }

        $user = User::query()->where('email', $data['email'])->first();
        if (! $user || ! $user->password || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 60);

            return response()->json(['message' => 'That email and password do not match.', 'code' => 'invalid_credentials'], 422);
        }
        RateLimiter::clear($key);

        if ($user->isClient() || ! $user->can('planner.view')) {
            return response()->json([
                'message' => 'Your account does not have Chakra Planner access yet. Ask an admin to tick it under Users → Permissions.',
                'code' => 'no_access',
            ], 403);
        }

        $issued = PlannerSession::issue($user, $data);

        return response()->json(['token' => $issued['plain']] + self::profile($user, $issued['session']), 201);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(self::profile($request->user(), $request->attributes->get('planner_session')));
    }

    public function logout(Request $request): Response
    {
        $request->attributes->get('planner_session')?->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    public static function profile(User $user, ?PlannerSession $session): array
    {
        $settings = PlannerSetting::query()->whereKey(1)->first();
        $synced = NotionSyncRunner::lastSyncedAt();

        return [
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role],
            'abilities' => [
                'view' => true,
                'edit' => $user->can('planner.edit'),
                'manage' => $user->can('planner.manage'),
            ],
            'session' => $session ? ['id' => $session->id, 'device_name' => $session->device_name] : null,
            'realtime' => [
                'enabled' => (bool) $settings?->realtimeEnabled(),
                'provider' => 'ably',
                'channel' => PlannerRealtime::CHANNEL,
                'client_id' => PlannerRealtime::clientId($user),
                'token_url' => route('api.planner.realtime.token'),
            ],
            'notion' => [
                'connected' => \App\Models\NotionSetting::query()->whereKey(1)->first()?->isConfigured() ?? false,
                'last_synced_at' => $synced?->toIso8601String(),
            ],
            'server_time' => now()->toIso8601String(),
        ];
    }
}
