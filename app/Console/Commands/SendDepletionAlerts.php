<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\ContentDepletionWarning;
use App\Support\ContentForecast;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

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
            Notification::send($client->alertRecipients($recipients), new ContentDepletionWarning($client, $row));

            $client->forceFill([
                'forecast_alert_depletion_date' => $depletionDate,
                'forecast_alert_sent_at' => now(),
            ])->save();

            $sent++;
        }

        $this->info("{$sent} depletion alert(s) sent, {$critical->count()} client(s) currently critical.");

        return self::SUCCESS;
    }
}
