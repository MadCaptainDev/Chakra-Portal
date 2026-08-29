<?php

namespace App\Console\Commands;

use App\Jobs\SendWhatsappCampaignMessage;
use App\Models\WhatsappCampaign;
use Illuminate\Console\Command;

/**
 * The tick that turns a scheduled campaign into actual sends.
 *
 * Runs every minute (see routes/console.php). Three jobs each run, in order:
 *
 * 1. Promote every due `scheduled` campaign to `sending`.
 * 2. For every campaign already `sending`, queue one
 *    SendWhatsappCampaignMessage per log row that has neither been dispatched
 *    nor sent -- the queue's own `whatsapp-campaign` rate limit (on the job's
 *    middleware) is what actually paces the send, not this command.
 * 3. Any `sending` campaign with nothing left pending is `completed`.
 */
class DispatchWhatsappCampaigns extends Command
{
    protected $signature = 'whatsapp:dispatch-campaigns';

    protected $description = 'Promote due campaigns to sending and queue their pending messages';

    public function handle(): int
    {
        $promoted = WhatsappCampaign::where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->update(['status' => 'sending', 'started_at' => now()]);

        if ($promoted > 0) {
            $this->line("Promoted {$promoted} due campaign(s) to sending.");
        }

        $sendingCampaigns = WhatsappCampaign::where('status', 'sending')->get();

        $dispatched = 0;

        foreach ($sendingCampaigns as $campaign) {
            $pendingLogs = $campaign->logs()
                ->where('status', 'pending')
                ->whereNull('dispatched_at')
                ->get();

            foreach ($pendingLogs as $log) {
                // Stamped before the job is even queued -- the next tick must
                // not see this row as still-undispatched just because the
                // job it queued a minute ago hasn't run yet.
                $log->update(['dispatched_at' => now()]);

                SendWhatsappCampaignMessage::dispatch($log->id);
                $dispatched++;
            }

            // Nothing left pending means every log has settled to sent or
            // failed (or was skipped as already-dispatched-but-not-yet-run,
            // which still counts as pending and keeps the campaign open).
            $stillPending = $campaign->logs()->where('status', 'pending')->exists();

            if (! $stillPending) {
                $campaign->update(['status' => 'completed', 'completed_at' => now()]);
            }
        }

        if ($dispatched > 0) {
            $this->line("Queued {$dispatched} message(s).");
        }

        return self::SUCCESS;
    }
}
