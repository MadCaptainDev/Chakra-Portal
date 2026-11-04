<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Planner\PlannerAuthController;
use App\Http\Controllers\Controller;
use App\Mcp\Server;
use App\Models\AppNotification;
use App\Models\PlannerSession;
use App\Models\User;
use App\Support\PlannerVisuals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Signing the mobile app in and out. Email + portal password once; the app
 * keeps the token in the iOS Keychain / Android Keystore and sends it as
 * "Authorization: Bearer chakraplanner_..." from then on. Tokens do not expire; they
 * end on logout, on revoke (/planner/setup) or when the account
 * becomes a client.
 */
class MobileAuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['required', 'string', 'max:120'],
            'platform' => ['nullable', 'string', 'in:ios,android'],
            'app_version' => ['nullable', 'string', 'max:40'],
        ]);

        $key = 'mobile-login:'.Str::lower($data['email']).'|'.$request->ip();
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

        if ($user->isClient()) {
            return response()->json(['message' => 'The Chakra app is for the studio team.', 'code' => 'no_access'], 403);
        }

        $issued = PlannerSession::issue($user, [
            'device_name' => $data['device_name'],
            // "ios-app" keeps these apart from the desktop planner in the device list.
            'platform' => ($data['platform'] ?? 'ios').'-app',
            'app_version' => $data['app_version'] ?? null,
        ]);

        return response()->json(['token' => $issued['plain']] + self::profile($user, $issued['session']), 201);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(self::profile($request->user(), $request->attributes->get('planner_session')));
    }

    public function logout(Request $request): Response
    {
        /** @var PlannerSession|null $session */
        $session = $request->attributes->get('planner_session');
        // The phone's push token goes with the session: a signed-out phone must stop buzzing.
        if ($token = $request->input('push_token')) {
            $request->user()->pushTokens()->where('token_hash', hash('sha256', (string) $token))->delete();
        }
        $session?->delete();

        return response()->noContent();
    }

    /** @return array<string, list<string>> */
    public static function permissions(User $user): array
    {
        $gate = \Illuminate\Support\Facades\Gate::forUser($user);
        $granted = [];
        foreach (\App\Support\Permission::modules() as $module => $config) {
            $abilities = array_values(array_filter($config['abilities'], fn (string $a) => $gate->allows($module.'.'.$a)));
            if ($abilities !== []) {
                $granted[$module] = $abilities;
            }
        }

        return $granted;
    }

    /** @return array<string, mixed> */
    public static function profile(User $user, ?PlannerSession $session): array
    {
        $planner = PlannerAuthController::profile($user, $session);
        $actions = array_map(fn ($tool) => $tool->name(), app(Server::class)->toolsFor($user));

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'first_name' => strtok((string) $user->name, ' ') ?: $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'avatar_url' => PlannerVisuals::avatar($user),
                'is_admin' => $user->isAdmin(),
                'logs_work' => $user->logsWork(),
            ],
            'features' => [
                'today' => true,
                'notifications' => true,
                'planner' => $planner['abilities']['view'],
                'planner_edit' => $planner['abilities']['edit'],
                'editing' => true,
                'editor_updates' => $planner['abilities']['editor_updates'],
                'review' => $planner['abilities']['review'],
                'team_hours' => $user->isAdmin(),
                'shoots_all' => $user->can('shoots.view'),
                // The app's Admin area: admins, and anyone managing invoices or shoots.
                'admin_area' => $user->isAdmin() || $user->can('invoices.view') || $user->can('shoots.edit'),
                'manages_team' => $user->managesAnyone(),
            ],
            'actions' => $actions,
            // module => abilities this person has, exactly as the portal's
            // gates answer (admins: everything). For showing admin screens.
            'permissions' => self::permissions($user),
            'unread_notifications' => AppNotification::query()->where('user_id', $user->id)->whereNull('read_at')->count(),
            'session' => $planner['session'],
            'realtime' => $planner['realtime'],
            'portal_url' => url('/'),
            'server_time' => now()->toIso8601String(),
            'timezone' => config('app.timezone'),
        ];
    }
}
