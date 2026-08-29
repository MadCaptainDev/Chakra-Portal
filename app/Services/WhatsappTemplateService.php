<?php

namespace App\Services;

use App\Models\WhatsappSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The list of Meta message templates this account can send.
 *
 * Templates live in Meta, not in this database -- there is nothing to store
 * locally, only to read and cache, because listing them is the same Graph
 * call whoever asks and however often. Five minutes of caching is long
 * enough that a campaign-builder screen does not refetch on every keystroke,
 * and short enough that a template approved five minutes ago shows up
 * without anyone needing to know a cache exists.
 */
class WhatsappTemplateService
{
    private const CACHE_KEY = 'whatsapp.templates';

    private const CACHE_MINUTES = 5;

    public function __construct(private readonly WhatsappSetting $settings) {}

    public static function make(): self
    {
        return new self(WhatsappSetting::current());
    }

    /**
     * All templates Meta has for this business account, optionally narrowed
     * to the ones actually usable -- a draft or rejected template cannot be
     * sent, so a campaign picker has no use for it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(bool $approvedOnly = true): array
    {
        $templates = Cache::remember(
            self::CACHE_KEY,
            now()->addMinutes(self::CACHE_MINUTES),
            fn () => $this->fetch()
        );

        if (! $approvedOnly) {
            return $templates;
        }

        return array_values(array_filter(
            $templates,
            fn (array $template) => ($template['status'] ?? null) === 'APPROVED'
        ));
    }

    /**
     * Bust the cache and read Meta again -- the "refresh" button on a
     * template picker, for when a template was approved after the cached
     * copy was taken.
     *
     * @return array<int, array<string, mixed>>
     */
    public function refresh(): array
    {
        Cache::forget(self::CACHE_KEY);

        return $this->list();
    }

    /**
     * The raw Graph call, mirroring CheckWhatsappPermissions' proven shape
     * for this exact endpoint.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetch(): array
    {
        $businessAccountId = $this->settings->business_account_id;

        if (blank($businessAccountId)) {
            return [];
        }

        try {
            $response = (new WhatsappGraph($this->settings))->get(
                "{$businessAccountId}/message_templates",
                ['fields' => 'name,status,language,category,components', 'limit' => 100]
            );
        } catch (RuntimeException $e) {
            /*
             * Not configured (no access token yet) or Meta is unreachable --
             * either way this is an empty state for the caller to render, not
             * a 500 on a screen that just wants to list templates.
             */
            Log::warning('WhatsApp template list could not be fetched.', ['error' => $e->getMessage()]);

            return [];
        }

        return $response['data'] ?? [];
    }
}
