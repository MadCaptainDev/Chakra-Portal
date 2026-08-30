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
     * Submit a new template to Meta for approval.
     *
     * Templates are not created here -- this is a thin wrapper over the one
     * Graph call, mirroring `list()`'s shape. Meta reviews every submission
     * before it can send, so the caller gets back Meta's own response
     * (typically `{"status": "PENDING", ...}`) to show, not a fake "done".
     *
     * @param  array{name: string, category: string, language: string, header?: ?string, body: string, body_example?: array<int, string>, footer?: ?string, buttons?: array<int, array<string, mixed>>}  $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $businessAccountId = $this->settings->business_account_id;

        if (blank($businessAccountId)) {
            throw new RuntimeException(
                'WhatsApp is not configured: set the Business Account ID in Settings -> WhatsApp.'
            );
        }

        $components = [];

        if (filled($data['header'] ?? null)) {
            $components[] = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $data['header']];
        }

        $body = ['type' => 'BODY', 'text' => $data['body']];

        // Meta requires one sample value per {{n}} in the body before it
        // will review a template that has any -- without it the create call
        // itself is rejected with "component of type BODY is missing
        // expected field(s) (example)", never mind the content.
        if (filled($data['body_example'] ?? null)) {
            $body['example'] = ['body_text' => [$data['body_example']]];
        }

        $components[] = $body;

        if (filled($data['footer'] ?? null)) {
            $components[] = ['type' => 'FOOTER', 'text' => $data['footer']];
        }

        /*
         * Raw Meta button defs, not yet exposed on the create-template form
         * (WhatsappTemplateController@create) -- so far the only caller is
         * the system's own "invoice_ready" template (a dynamic URL button),
         * seeded once from a console command rather than clicked together in
         * the UI. A second caller is the trigger to build the form fields.
         */
        if (filled($data['buttons'] ?? null)) {
            $components[] = ['type' => 'BUTTONS', 'buttons' => $data['buttons']];
        }

        $response = (new WhatsappGraph($this->settings))->post("{$businessAccountId}/message_templates", [
            'name' => $data['name'],
            'language' => $data['language'],
            'category' => $data['category'],
            'components' => $components,
        ]);

        // The list just changed (a PENDING template now exists that did not
        // before) -- next visit to the index should see it, not the stale cache.
        Cache::forget(self::CACHE_KEY);

        return $response;
    }

    /**
     * Remove a template Meta rejected (or one no longer wanted) so its name
     * is free to resubmit -- Meta refuses a second submission under a name
     * that already has content in that language, rejected or not.
     */
    public function delete(string $name): void
    {
        $businessAccountId = $this->settings->business_account_id;

        if (blank($businessAccountId)) {
            throw new RuntimeException(
                'WhatsApp is not configured: set the Business Account ID in Settings -> WhatsApp.'
            );
        }

        (new WhatsappGraph($this->settings))->delete("{$businessAccountId}/message_templates", ['name' => $name]);

        Cache::forget(self::CACHE_KEY);
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
