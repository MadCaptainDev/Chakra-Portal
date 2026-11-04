<?php

namespace App\Services\LiveActivities;

use App\Models\LiveActivityToken;
use App\Models\User;
use App\Services\Push\ApnsClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Starting, updating and ending the iPhone app's Live Activities over APNs
 * (apns-push-type: liveactivity, topic <bundle>.push-type.liveactivity).
 * Never throws: every caller is a side effect of something a person did.
 *
 * Dates inside content-state are unix epoch seconds (the app decodes them
 * so). A push-to-start creates the activity on the phone, which then
 * registers that activity's own update token; until it does, there is
 * nothing to update, so a second start is held off by a cache marker.
 */
class LiveActivities
{
    public static function attributesType(string $activity): string
    {
        return $activity.'Attributes';
    }

    public static function ready(): bool
    {
        return rescue(fn () => ApnsClient::make()->isConfigured(), false, false);
    }

    /** @return Collection<int, LiveActivityToken> */
    public static function updateTokens(string $activity, ?int $userId = null, ?int $shootId = null, bool $todayOnly = false): Collection
    {
        return LiveActivityToken::query()
            ->where('type', LiveActivityToken::TYPE_UPDATE)->where('activity', $activity)->whereNull('ended_at')
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->when($shootId, fn ($q) => $q->where('shoot_id', $shootId))
            ->when($todayOnly, fn ($q) => $q->where('created_at', '>=', today()))
            ->get();
    }

    /**
     * Update this person's running activity, or start one if they have none
     * (and none was started in the last $markerTtl seconds for this key).
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $state
     * @param  array{title: string, body: string}  $alert
     */
    public static function updateOrStart(User $user, string $activity, array $attributes, array $state, array $alert, ?int $shootId, string $marker, int $markerTtl, bool $alertOnUpdate = false, bool $todayOnly = false): string
    {
        $running = self::updateTokens($activity, $user->id, $shootId, $todayOnly);
        if ($running->isNotEmpty()) {
            self::update($running, $state, $alertOnUpdate ? $alert : null);

            return 'updated';
        }

        if (! Cache::add('la-start:'.$marker, 1, $markerTtl)) {
            return 'waiting'; // started a moment ago; its update token is on its way
        }

        return self::start($user, $activity, $attributes, $state, $alert) > 0 ? 'started' : 'no-device';
    }

    /** @return int how many devices were asked to start it */
    public static function start(User $user, string $activity, array $attributes, array $state, array $alert): int
    {
        $tokens = LiveActivityToken::query()->where('type', LiveActivityToken::TYPE_START)
            ->where('activity', $activity)->where('user_id', $user->id)->get();

        foreach ($tokens as $token) {
            self::send($token, [
                'event' => 'start',
                'content-state' => (object) $state,
                'attributes-type' => self::attributesType($activity),
                'attributes' => (object) $attributes,
                'alert' => ['title' => $alert['title'], 'body' => $alert['body'], 'sound' => 'default'],
            ], 10);
        }

        return $tokens->count();
    }

    /** @param  Collection<int, LiveActivityToken>  $tokens */
    public static function update(Collection $tokens, array $state, ?array $alert = null, ?int $staleAt = null): void
    {
        foreach ($tokens as $token) {
            self::send($token, array_filter([
                'event' => 'update',
                'content-state' => (object) $state,
                'stale-date' => $staleAt,
                'alert' => $alert ? ['title' => $alert['title'], 'body' => $alert['body'], 'sound' => 'default'] : null,
            ], fn ($v) => $v !== null), $alert ? 10 : 5);
        }
    }

    /** @param  Collection<int, LiveActivityToken>  $tokens */
    public static function end(Collection $tokens, array $state, ?int $dismissAt = null): void
    {
        foreach ($tokens as $token) {
            self::send($token, [
                'event' => 'end',
                'content-state' => (object) $state,
                'dismissal-date' => $dismissAt ?? time(),
            ], 10);
            $token->forceFill(['ended_at' => now()])->save();
        }
    }

    private static function send(LiveActivityToken $token, array $aps, int $priority): void
    {
        try {
            $apns = ApnsClient::make();
            if (! $apns->isConfigured()) {
                return;
            }
            $result = $apns->send($token->token, $token->environment, 'liveactivity', $apns->bundleId().'.push-type.liveactivity',
                ['aps' => ['timestamp' => time()] + $aps], $priority);

            if ($result['gone']) {
                $token->delete();

                return;
            }
            $token->forceFill([
                'environment' => $result['environment'],
                'last_sent_at' => now(),
                'failure_reason' => $result['ok'] ? null : mb_substr((string) $result['reason'], 0, 120),
            ])->save();
        } catch (Throwable $e) {
            Log::error('Live Activity push failed.', ['token_id' => $token->id, 'error' => $e->getMessage()]);
        }
    }
}
