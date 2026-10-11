<?php

namespace App\Http\Controllers;

use App\Models\ContentItem;
use App\Models\ContentItemChange;
use App\Models\PlannerSetting;
use App\Services\Notion\ContentSyncService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notion's webhooks: a planner page changed in Notion, so pull that one page
 * now instead of waiting for the next full sync -- which is what makes an
 * edit made in Notion show up in every open Chakra Planner within a minute.
 *
 * Setting it up (Notion integration → Webhooks → create subscription with
 * this URL) makes Notion POST a one-time {"verification_token": ...}. That
 * token is kept (PlannerSetting) and shown on the Chakra Planner page in the
 * portal, where an admin copies it back into Notion to verify. Every event
 * after that is signed with it: X-Notion-Signature = "sha256=" + HMAC-SHA256
 * of the raw body.
 *
 * A token is only accepted while none is held. Otherwise anyone who found
 * this URL could post their own token and then sign events with it; to
 * re-create the subscription, "Forget webhook token" on the portal page first.
 *
 * Notion retries an event it did not get a 2xx for (up to 8 times over a
 * day), so a Notion outage answers 503 to be retried; anything about a page
 * that is not ours answers 200 and is dropped.
 */
class NotionWebhookController extends Controller
{
    private const PAGE_EVENTS = ['page.created', 'page.properties_updated', 'page.undeleted', 'page.moved'];

    public function receive(Request $request, ContentSyncService $notion): JsonResponse
    {
        $raw = $request->getContent();
        $body = json_decode($raw, true);
        if (! is_array($body)) {
            return response()->json(['message' => 'Expected JSON.'], 400);
        }

        $settings = PlannerSetting::current();

        if (isset($body['verification_token']) && ! isset($body['type'])) {
            return $this->verification($settings, (string) $body['verification_token']);
        }

        $secret = (string) $settings->notion_webhook_secret;
        $signature = (string) $request->header('X-Notion-Signature');
        if ($secret === '' || ! hash_equals('sha256='.hash_hmac('sha256', $raw, $secret), $signature)) {
            return response()->json(['message' => 'Bad signature.'], 401);
        }

        $settings->forceFill(['notion_webhook_last_event_at' => now()])->save();

        $type = (string) ($body['type'] ?? '');
        $pageId = (string) ($body['entity']['id'] ?? '');
        if (($body['entity']['type'] ?? null) !== 'page' || $pageId === '') {
            return response()->json(['ok' => true, 'ignored' => $type]);
        }

        if ($type === 'page.deleted') {
            $this->gone($pageId);

            return response()->json(['ok' => true]);
        }
        if (! in_array($type, self::PAGE_EVENTS, true)) {
            return response()->json(['ok' => true, 'ignored' => $type]);
        }

        try {
            $page = $notion->fetchPage($pageId);
        } catch (RequestException $e) {
            if ($e->response->status() === 404) {
                return response()->json(['ok' => true, 'ignored' => 'not shared with the integration']);
            }
            Log::warning('Notion webhook: could not fetch the page.', ['page' => $pageId, 'status' => $e->response->status()]);

            return response()->json(['message' => 'Notion unavailable, retry.'], 503);
        } catch (Throwable $e) {
            Log::warning('Notion webhook: Notion unreachable.', ['page' => $pageId, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Notion unavailable, retry.'], 503);
        }

        $before = ContentItem::query()->where('notion_page_id', $page['id'] ?? $pageId)->first();
        $was = $before ? ['published_date' => $before->published_date?->toDateString(), 'status' => $before->status] : null;

        $item = $notion->applyPage($page);
        if ($item && $was) {
            $now = ['published_date' => $item->published_date?->toDateString(), 'status' => $item->status];
            foreach ($now as $field => $value) {
                if ($was[$field] !== $value) {
                    ContentItemChange::record($item, null, $field, $was[$field], $value, ContentItemChange::ORIGIN_NOTION);
                }
            }
        }

        return response()->json(['ok' => true, 'item' => $item?->id]);
    }

    private function verification(PlannerSetting $settings, string $token): JsonResponse
    {
        if ($token === '') {
            return response()->json(['message' => 'Empty token.'], 400);
        }
        if (filled($settings->notion_webhook_secret)) {
            // The same token again ("Resend token" in Notion) is fine; a new
            // one is ignored until an admin forgets the old one.
            return response()->json(['ok' => true, 'kept' => hash_equals((string) $settings->notion_webhook_secret, $token) ? 'same' : 'existing']);
        }

        $settings->forceFill(['notion_webhook_secret' => $token, 'notion_webhook_secret_at' => now()])->save();

        return response()->json(['ok' => true]);
    }

    /** Deleted in Notion: flagged, never removed -- same rule as ContentSyncService::markMissing(). */
    private function gone(string $pageId): void
    {
        $dashed = preg_replace('/^(\w{8})(\w{4})(\w{4})(\w{4})(\w{12})$/', '$1-$2-$3-$4-$5', str_replace('-', '', $pageId));
        $item = ContentItem::query()->whereIn('notion_page_id', array_unique([$pageId, $dashed]))->first();

        if ($item && ! $item->notion_missing_since) {
            $item->forceFill(['notion_missing_since' => now()])->save();
        }
    }
}
