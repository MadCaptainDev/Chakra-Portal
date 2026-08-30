<?php

namespace App\Console\Commands;

use App\Jobs\SendWhatsappCampaignMessage;
use App\Models\WhatsappCampaign;
use App\Models\WhatsappCampaignLog;
use Illuminate\Console\Command;

/**
 * The tick that turns a scheduled campaign into actual sends.
 *
 * Runs every minute (see routes/console.php, which also wraps it in
 * ->withoutOverlapping() -- a belt-and-suspenders guard, not the actual fix;
 * see claimAndDispatch() for that). Three jobs each run, in order:
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
            $dispatched += $this->claimAndDispatch($campaign);

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

    /**
     * Claims this campaign's still-undispatched pending logs one at a time
     * and queues a job for each one actually claimed.
     *
     * The claim itself -- a single-row `UPDATE ... WHERE id = ? AND
     * dispatched_at IS NULL` -- is what closes the race a long-running or
     * overlapping run can otherwise hit: a run that only selects candidate
     * ids and then updates them in a separate step (what this command used
     * to do, and what a batched "UPDATE the whole set, then re-select by a
     * shared timestamp marker" would still risk if two runs stamp the same
     * marker within the same second) leaves a window where a second run can
     * see the same still-NULL row and dispatch it again before the first
     * run's update lands. Checking each row's own update-affected-row-count
     * has no such window: the database itself guarantees that of any number
     * of processes racing to run this exact single-row UPDATE, at most one
     * gets an affected count of 1 for that id -- every other one gets 0 and
     * skips it, with no shared value for two concurrent claims to collide on.
     *
     * @return int how many logs this call actually claimed and dispatched
     */
    private function claimAndDispatch(WhatsappCampaign $campaign): int
    {
        $candidateIds = $campaign->logs()
            ->where('status', 'pending')
            ->whereNull('dispatched_at')
            ->pluck('id');

        $dispatched = 0;

        foreach ($candidateIds as $logId) {
            $claimed = WhatsappCampaignLog::whereKey($logId)
                ->where('status', 'pending')
                ->whereNull('dispatched_at')
                ->update(['dispatched_at' => now()]);

            if ($claimed !== 1) {
                // Lost the race for this one -- another run's identical
                // claim got there first, and it (not this run) is
                // responsible for dispatching its job.
                continue;
            }

            SendWhatsappCampaignMessage::dispatch($logId);
            $dispatched++;
        }

        return $dispatched;
    }
}
