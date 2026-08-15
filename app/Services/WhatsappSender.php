<?php

namespace App\Services;

use App\Models\WhatsappSetting;
use App\Models\WhatsappWebhookEvent;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sending, which is the half the webhook does not do.
 *
 * Two ways out, and which one is legal depends entirely on timing:
 *
 * - sendTemplate() works at any time, but only with a template Meta has already
 *   approved.
 * - sendText() is free-form and only works inside the 24-hour customer service
 *   window -- that is, within a day of that person last messaging the studio.
 *   Outside it Meta rejects the call, and no amount of retrying changes that.
 *
 * This is not our rule and cannot be worked around; the first message to
 * somebody who has never written to us is always a template.
 */
class WhatsappSender
{
    public function __construct(private readonly WhatsappSetting $settings) {}

    public static function make(): self
    {
        return new self(WhatsappSetting::current());
    }

    /**
     * Send an approved template. This is the one that works cold.
     *
     * @param  array<int, string>  $bodyParameters  fills {{1}}, {{2}} ... in order
     * @return array{wamid: string|null, response: array<string, mixed>}
     */
    public function sendTemplate(
        string $to,
        string $template,
        string $language = 'en_US',
        array $bodyParameters = []
    ): array {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => self::normalise($to),
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => $language],
            ],
        ];

        if ($bodyParameters !== []) {
            $payload['template']['components'] = [[
                'type' => 'body',
                'parameters' => array_map(
                    fn (string $text) => ['type' => 'text', 'text' => $text],
                    $bodyParameters
                ),
            ]];
        }

        return $this->send($payload, summary: sprintf('[template: %s]', $template));
    }

    /**
     * Send free text. Only reaches someone who messaged us in the last 24 hours.
     *
     * @return array{wamid: string|null, response: array<string, mixed>}
     */
    public function sendText(string $to, string $body): array
    {
        return $this->send([
            'messaging_product' => 'whatsapp',
            'to' => self::normalise($to),
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $body],
        ], summary: $body);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{wamid: string|null, response: array<string, mixed>}
     */
    private function send(array $payload, string $summary): array
    {
        if (! $this->settings->canSend()) {
            throw new RuntimeException(
                'WhatsApp sending is not configured: set the access token and phone number ID in Settings -> WhatsApp.'
            );
        }

        // The HTTP call, the token and the error handling all live in
        // WhatsappGraph, so a Meta error reads the same however it was reached.
        $response = (new WhatsappGraph($this->settings))
            ->post($this->settings->phone_number_id.'/messages', $payload);

        $wamid = data_get($response, 'messages.0.id');

        Log::info('WhatsApp message sent.', ['to' => $payload['to'], 'wamid' => $wamid]);

        /*
         * Recorded in the same log the webhook writes to, so the message and the
         * sent/delivered/read events that follow it sit together under one
         * wamid. A sent message that only exists in a log file cannot be
         * reconciled with the statuses that arrive minutes later.
         */
        $this->record($payload, $wamid, $summary);

        return ['wamid' => $wamid, 'response' => $response];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(array $payload, ?string $wamid, string $summary): void
    {
        try {
            WhatsappWebhookEvent::recordOutgoing(
                to: $payload['to'],
                wamid: $wamid,
                messageType: $payload['type'],
                summary: $summary,
                payload: $payload,
            );
        } catch (\Throwable $e) {
            // The message is already gone; failing to file it must not turn a
            // successful send into an exception the caller sees as a failure.
            Log::error('WhatsApp message sent but not recorded.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Meta wants digits only, with a country code and no plus, spaces or
     * dashes. A ten-digit Indian mobile typed the way people say it is assumed
     * to be +91 -- getting this wrong is silent, because Meta accepts the call
     * and simply never delivers.
     */
    public static function normalise(string $number): string
    {
        $digits = preg_replace('/\D+/', '', $number) ?? '';

        if (strlen($digits) === 10) {
            $digits = '91'.$digits;
        }

        return $digits;
    }
}
