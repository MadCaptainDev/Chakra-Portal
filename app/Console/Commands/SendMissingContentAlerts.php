<?php

namespace App\Console\Commands;

use App\Models\Shoot;
use App\Models\User;
use App\Notifications\ShootContentMissing;
use App\Services\WhatsappSender;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * One push per shoot that's completed with nothing added to the Reel
 * Planner yet -- see Shoot::scopeNeedsContentAdded(). Run daily, after the
 * Notion syncs (routes/console.php), so a shoot whose videos were added
 * this morning doesn't get flagged on yesterday's data.
 *
 * Idempotent via content_missing_alert_sent_at, same shape as
 * reminder_sent_at on this table -- fires once per shoot, not every day it
 * stays uncorrected. Unlike SendDepletionAlerts, there's no "the number
 * moved" case to re-fire on: a shoot either has content linked or it
 * doesn't, so a plain sent-once flag is enough here.
 */
class SendMissingContentAlerts extends Command
{
    protected $signature = 'shoots:send-missing-content-alerts';

    protected $description = 'Warn staff about completed shoots with nothing added to the Reel Planner yet';

    /**
     * A push is for something to act on now. A shoot from four months ago
     * still missing content is more likely abandoned or already
     * written off than something a push will get anyone to fix today --
     * and on this feature's first run, every historical gap would otherwise
     * fire at once. The dashboard count and the Shoots board badge (see
     * Shoot::scopeNeedsContentAdded()) still show the full backlog; only
     * the push is windowed.
     */
    private const ALERT_WINDOW_DAYS = 45;

    public function handle(): int
    {
        $shoots = Shoot::query()
            ->needsContentAdded()
            ->whereNull('content_missing_alert_sent_at')
            ->where('starts_at', '>=', now()->subDays(self::ALERT_WINDOW_DAYS))
            ->with('client.teamMembers')
            ->get();

        if ($shoots->isEmpty()) {
            $this->info('Nothing to alert — every completed shoot has content linked (or was already alerted).');

            return self::SUCCESS;
        }

        $fallback = User::canSee('shoots')->get();
        $sent = 0;

        foreach ($shoots as $shoot) {
            $recipients = $shoot->client
                ? $shoot->client->alertRecipients($fallback)
                : $fallback;

            Notification::send($recipients, new ShootContentMissing($shoot));
            $this->sendWhatsapp($shoot, $recipients);

            $shoot->forceFill(['content_missing_alert_sent_at' => now()])->save();
            $sent++;
        }

        $this->info("{$sent} missing-content alert(s) sent.");

        return self::SUCCESS;
    }

    /**
     * Same alert over WhatsApp, to whichever of these recipients have a
     * phone on file -- skipped quietly for anyone who doesn't, same as no
     * push token quietly means no push. One bad number or an unapproved
     * template must not stop the rest from being told, so failures are
     * logged rather than thrown.
     *
     * @param  Collection<int, User>  $recipients
     */
    private function sendWhatsapp(Shoot $shoot, Collection $recipients): void
    {
        foreach ($recipients as $recipient) {
            if (blank($recipient->phone)) {
                continue;
            }

            try {
                WhatsappSender::make()->sendTemplate(
                    to: $recipient->phone,
                    template: Shoot::WHATSAPP_TEMPLATE_MISSING_CONTENT,
                    bodyParameters: [$shoot->title, $shoot->starts_at->format('D j M')],
                );
            } catch (RuntimeException $e) {
                Log::error('Missing-content alert WhatsApp send failed.', [
                    'shoot_id' => $shoot->id,
                    'user_id' => $recipient->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
