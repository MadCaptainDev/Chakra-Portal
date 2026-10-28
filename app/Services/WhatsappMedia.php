<?php

namespace App\Services;

use App\Models\WhatsappSetting;
use App\Models\WhatsappWebhookEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The photos, videos, voice notes and files in inbox threads, kept on our
 * own disk (storage/app/private/whatsapp-media, never public -- they are
 * served only through the signed-in inbox).
 *
 * Meta's copy does not last: the link in the webhook expires within days
 * and the media id stops resolving soon after. So fetchAndStore() runs the
 * moment a media message arrives (WhatsappWebhookEventObserver), and the
 * inbox only ever has to ask Meta again for something that arrived before
 * this existed.
 */
class WhatsappMedia
{
    public const KINDS = ['image', 'video', 'audio', 'document', 'sticker'];

    public static function has(WhatsappWebhookEvent $event): bool
    {
        return in_array($event->message_type, self::KINDS, true)
            && filled(data_get($event->payload, $event->message_type.'.id'));
    }

    public static function path(WhatsappWebhookEvent $event): string
    {
        return 'whatsapp-media/'.$event->id;
    }

    public static function stored(WhatsappWebhookEvent $event): bool
    {
        return Storage::disk('local')->exists(self::path($event));
    }

    /**
     * Download it from Meta unless we already have it: the webhook's own
     * link first (it needs no lookup while fresh), then the media id.
     *
     * @throws RuntimeException when Meta no longer has it
     */
    public static function fetchAndStore(WhatsappWebhookEvent $event): void
    {
        if (! self::has($event) || self::stored($event)) {
            return;
        }

        $settings = WhatsappSetting::current();
        $file = data_get($event->payload, $event->message_type, []);
        $download = null;

        if (filled($file['url'] ?? null) && filled($settings->access_token)) {
            $response = Http::withToken($settings->access_token)->timeout(60)->get($file['url']);

            if ($response->successful()) {
                $download = [
                    'body' => $response->body(),
                    'mime' => $file['mime_type'] ?? $response->header('Content-Type') ?: 'application/octet-stream',
                ];
            }
        }

        $download ??= (new WhatsappGraph($settings))->downloadMedia((string) $file['id']);

        $disk = Storage::disk('local');
        $disk->put(self::path($event), $download['body']);
        $disk->put(self::path($event).'.mime', $download['mime']);
    }

    public static function mime(WhatsappWebhookEvent $event): string
    {
        $disk = Storage::disk('local');
        $sidecar = self::path($event).'.mime';

        return $disk->exists($sidecar)
            ? trim($disk->get($sidecar))
            : (data_get($event->payload, $event->message_type.'.mime_type') ?? 'application/octet-stream');
    }
}
