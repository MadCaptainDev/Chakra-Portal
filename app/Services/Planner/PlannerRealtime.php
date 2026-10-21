<?php

namespace App\Services\Planner;

use App\Models\ContentItem;
use App\Models\PlannerSetting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Live updates for Chakra Planner, through Ably.
 *
 * This host cannot keep a websocket server running (no long-lived processes),
 * so the sockets are Ably's: every open copy of the app subscribes to one
 * channel, and the portal publishes to it over Ably's REST API whenever an
 * item changes. The app never talks to Ably with the portal's key -- it asks
 * /api/planner/realtime/token for a short-lived, signed token request that
 * can only subscribe and show presence on that one channel.
 *
 * With no Ably key saved everything here is a no-op and the app falls back to
 * polling /api/planner/items?updated_since=.
 *
 * Publishing never throws and never delays the person who made the change: it
 * is deferred until after the response, and a failure is a log line. The
 * change itself is already saved; a missed live event is caught up by the
 * app's next poll.
 */
class PlannerRealtime
{
    public const CHANNEL = 'planner';

    private const REST_HOST = 'https://main.realtime.ably.net';

    /** How long a realtime token lasts; Ably's SDK renews it through authUrl. */
    private const TOKEN_TTL_MS = 3_600_000;

    /** Container key for this request's queued items (item id => who changed it). */
    private const PENDING = 'planner.realtime.pending';

    /**
     * Queue an item for an "item.updated" event, sent once per item after
     * the response however many times it was saved during the request.
     *
     * The queue lives in the container, not a static, so it is per request
     * by construction; the named defer() replaces itself, so however many
     * items change there is one callback and it sees all of them.
     */
    public static function itemChanged(ContentItem $item): void
    {
        if (! self::settings()?->realtimeEnabled()) {
            return;
        }

        if (! app()->bound(self::PENDING)) {
            app()->instance(self::PENDING, new \ArrayObject);
        }
        $pending = app(self::PENDING);
        $actor = request()->user();
        $pending[$item->id] = $actor instanceof User ? ['id' => $actor->id, 'name' => $actor->name] : null;

        defer(function () use ($pending) {
            $queued = $pending->getArrayCopy();
            $pending->exchangeArray([]);

            $items = ContentItem::query()->with(PlannerItems::RELATIONS)->whereIn('id', array_keys($queued))->get();
            foreach ($items as $changed) {
                self::publish('item.updated', ['item' => PlannerItems::row($changed), 'by' => $queued[$changed->id] ?? null]);
            }
        }, 'planner.realtime.items');
    }

    /** After a full Notion sync: too many rows for one event each, so the app re-fetches. */
    public static function refreshed(string $status): void
    {
        if (! self::settings()?->realtimeEnabled()) {
            return;
        }

        $at = now()->toIso8601String();
        defer(fn () => self::publish('items.refreshed', ['at' => $at, 'message' => $status]), 'planner.realtime.refreshed');
    }

    /** Send one message to the planner channel. Never throws. */
    public static function publish(string $name, array $data): bool
    {
        $settings = self::settings();
        if (! $settings?->realtimeEnabled()) {
            return false;
        }

        [$keyName, $secret] = explode(':', (string) $settings->ably_key, 2);

        try {
            $response = Http::withBasicAuth($keyName, $secret)
                ->acceptJson()
                ->timeout(5)
                ->post(self::REST_HOST.'/channels/'.self::CHANNEL.'/messages', [
                    'name' => $name,
                    // "json" encoding: Ably's SDKs hand the app an object back.
                    'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'encoding' => 'json',
                ]);

            if ($response->failed()) {
                Log::warning('Ably refused a Chakra Planner event.', ['event' => $name, 'status' => $response->status(), 'body' => Str::limit($response->body(), 300)]);

                return false;
            }
        } catch (Throwable $e) {
            Log::warning('Ably was unreachable for a Chakra Planner event.', ['event' => $name, 'error' => $e->getMessage()]);

            return false;
        }

        return true;
    }

    /**
     * A signed Ably TokenRequest for this person: subscribe and presence on
     * the planner channel only, as clientId "user:{id}". The app passes it
     * to Ably's SDK, which exchanges it for a token directly with Ably -- the
     * portal never makes a network call here. Null when live updates are off.
     *
     * Signing follows Ably's token request spec: HMAC-SHA256 with the key's
     * secret over keyName, ttl, capability, clientId, timestamp and nonce,
     * each followed by a newline, base64-encoded.
     *
     * @return array<string, int|string>|null
     */
    public static function tokenRequest(User $user): ?array
    {
        $settings = self::settings();
        if (! $settings?->realtimeEnabled()) {
            return null;
        }

        [$keyName, $secret] = explode(':', (string) $settings->ably_key, 2);
        // Canonical form: no whitespace, channels and operations sorted.
        $capability = json_encode([self::CHANNEL => ['presence', 'subscribe']]);
        $clientId = self::clientId($user);
        $timestamp = (int) floor(microtime(true) * 1000);
        $nonce = Str::random(24);
        $ttl = self::TOKEN_TTL_MS;

        $mac = base64_encode(hash_hmac('sha256', implode("\n", [$keyName, $ttl, $capability, $clientId, $timestamp, $nonce])."\n", $secret, true));

        return compact('keyName', 'ttl', 'capability', 'clientId', 'timestamp', 'nonce', 'mac');
    }

    public static function clientId(User $user): string
    {
        return 'user:'.$user->id;
    }

    /**
     * The saved settings, without creating the row as a side effect -- this
     * runs on every ContentItem save, including in parts of the app that
     * have nothing to do with the planner. Fresh each call: it is one
     * primary-key read, and a cached copy would outlive a key being saved.
     */
    private static function settings(): ?PlannerSetting
    {
        try {
            return PlannerSetting::query()->whereKey(1)->first();
        } catch (Throwable) {
            // The table does not exist yet (mid-deploy, before the migration).
            return null;
        }
    }
}
