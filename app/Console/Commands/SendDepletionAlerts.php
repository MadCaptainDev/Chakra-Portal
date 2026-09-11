<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\User;
use App\Notifications\ContentDepletionWarning;
use App\Services\WhatsappSender;
use App\Support\ContentForecast;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * One push per client whose content is about to run out with nothing
 * booked, to staff who can act on it -- run daily, after the Notion syncs
 * that feed ContentForecast (see routes/console.php for the ordering).
 *
 * critical only, deliberately: `warning` (further out, still uncovered)
 * stays on the dashboard/Forecast page rather than pushing, so a client
 * three weeks out doesn't compete for attention with one three days out.
 *
 * Idempotency mirrors SendShootReminders/NotifyReportsReady, but with one
 * addition those don't need: a plain "already sent" flag would fire once
 * and never again even as the depletion date kept moving closer, so this
 * also remembers WHICH date was last alerted on (forecast_alert_depletion_date)
 * and only re-fires when the computed date has moved earlier than that, or
 * nothing has been sent yet.
 */
class SendDepletionAlerts extends Command
{
    protected $signature = 'content:send-depletion-alerts';

    protected $description = 'Warn staff about clients whose unposted content is about to run out with no shoot booked';

    public function handle(): int
    {
        $recipients = User::canSee('forecast')->get();

        if ($recipients->isEmpty()) {
            $this->warn('No staff can see the Forecast module — nobody to alert. Grant it under Setup → Users.');

            return self::SUCCESS;
        }

        $critical = ContentForecast::forAllClients()
            ->where('status', ContentForecast::STATUS_CRITICAL);

        $sent = 0;

        foreach ($critical as $row) {
            $client = $row['client'];
            $depletionDate = $row['depletion_date'];

            $alreadyAlerted = $client->forecast_alert_sent_at !== null
                && $client->forecast_alert_depletion_date !== null
                && $client->forecast_alert_depletion_date->isSameDay($depletionDate);

            if ($alreadyAlerted) {
                continue;
            }

            // The client's own account manager first -- see Client::
            // alertRecipients(); falls back to the broad Forecast-visible
            // list only when nobody's been assigned to this client yet.
            $theseRecipients = $client->alertRecipients($recipients);

            Notification::send($theseRecipients, new ContentDepletionWarning($client, $row));
            $this->sendWhatsapp($client, $row, $theseRecipients);

            $client->forceFill([
                'forecast_alert_depletion_date' => $depletionDate,
                'forecast_alert_sent_at' => now(),
            ])->save();

            $sent++;
        }

        $this->info("{$sent} depletion alert(s) sent, {$critical->count()} client(s) currently critical.");

        return self::SUCCESS;
    }

    /**
     * Same alert over WhatsApp, to whichever of these recipients have a
     * phone on file -- skipped quietly for anyone who doesn't, same as no
     * push token quietly means no push. One bad number or an unapproved
     * template must not stop the rest from being told, so failures are
     * logged rather than thrown.
     *
     * @param  array<string, mixed>  $row  A ContentForecast::forClient() row.
     * @param  Collection<int, User>  $recipients
     */
    private function sendWhatsapp(Client $client, array $row, Collection $recipients): void
    {
        $depletion = $row['depletion_date'];
        $remaining = $row['remaining'];

        $status = $remaining <= 0
            ? 'out of content already'
            : "running out around {$depletion->format('j M')} ({$remaining} left)";

        foreach ($recipients as $recipient) {
            if (blank($recipient->phone)) {
                continue;
            }

            try {
                WhatsappSender::make()->sendTemplate(
                    to: $recipient->phone,
                    template: Client::WHATSAPP_TEMPLATE_DEPLETION,
                    bodyParameters: [$client->name, $status],
                );
            } catch (RuntimeException $e) {
                Log::error('Content depletion WhatsApp send failed.', [
                    'client_id' => $client->id,
                    'user_id' => $recipient->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
