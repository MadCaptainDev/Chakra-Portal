<?php

namespace App\Jobs;

use App\Models\WhatsappCampaignLog;
use App\Models\WhatsappSetting;
use App\Services\WhatsappSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;

/**
 * Sends one campaign's message to one contact.
 *
 * One job per log row, not one job per campaign -- that is what lets the
 * queue's own `whatsapp-campaign` rate limit (see AppServiceProvider::boot())
 * pace the whole broadcast without this class needing a chunk/sleep loop of
 * its own, and what keeps one recipient's failure from touching any other
 * recipient's send.
 */
class SendWhatsappCampaignMessage implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $campaignLogId) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RateLimited('whatsapp-campaign')];
    }

    public function handle(): void
    {
        $log = WhatsappCampaignLog::with(['campaign', 'contact'])->find($this->campaignLogId);

        // The log (or what it points at) is gone -- nothing left to send.
        if (! $log || ! $log->campaign || ! $log->contact) {
            return;
        }

        if (! WhatsappSetting::current()->canSend()) {
            $log->update([
                'status' => 'failed',
                'error' => 'WhatsApp sending is not configured: set the access token and phone number ID in Settings -> WhatsApp.',
            ]);

            return;
        }

        $campaign = $log->campaign;
        $contact = $log->contact;

        $mergeFields = $contact->mergeFields();

        // Each entry in variable_mapping is either a literal string or one of
        // the var1..var5 keys mergeFields() knows how to resolve -- so a
        // campaign can mix "Hi {{contact's name-ish var}}" with a fixed word
        // like "Studio" in the same template without a second table.
        $bodyParameters = collect($campaign->variable_mapping ?? [])
            ->map(fn ($entry) => array_key_exists($entry, $mergeFields)
                ? (string) ($mergeFields[$entry] ?? '')
                : (string) $entry)
            ->values()
            ->all();

        try {
            $result = WhatsappSender::make()->sendTemplate(
                to: $contact->phone,
                template: $campaign->meta_template_name,
                language: $campaign->meta_template_language,
                bodyParameters: $bodyParameters,
            );

            $log->update([
                'status' => 'sent',
                'wamid' => $result['wamid'],
                'sent_at' => now(),
            ]);
        } catch (Throwable $e) {
            // One recipient's failure is not the job's failure -- caught here
            // so the queue keeps moving on to the rest of the phonebook
            // rather than the whole batch stalling (and retrying) on the one
            // number Meta rejected.
            $log->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
