<?php

namespace App\Console\Commands;

use App\Models\Shoot;
use App\Notifications\ShootReminderDue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Tomorrow's shoots, pushed to their crew tonight -- crew only, not the
 * client. A client already sees their own upcoming shoots on their own
 * portal page (client.shoots) every time they think to look; a call sheet
 * (call time, kit, who else is on it) is crew's own working document, not
 * something a client's day-before reminder should read like.
 *
 * Scheduled in the evening (see routes/console.php) rather than the
 * morning of: crew are the ones who need the lead time to actually
 * prepare, not be told the day has already started.
 */
class SendShootReminders extends Command
{
    protected $signature = 'shoots:send-reminders';

    protected $description = "Push tomorrow's call sheet to each shoot's crew, once";

    public function handle(): int
    {
        $tomorrow = now()->addDay();

        $shoots = Shoot::query()
            ->where('status', '!=', Shoot::STATUS_CANCELLED)
            ->whereDate('starts_at', $tomorrow->toDateString())
            ->whereNull('reminder_sent_at')
            ->with('crew.user')
            ->get();

        $sent = 0;

        foreach ($shoots as $shoot) {
            foreach ($shoot->crew as $crew) {
                if ($crew->user) {
                    Notification::send($crew->user, new ShootReminderDue($crew));
                }
            }

            $shoot->forceFill(['reminder_sent_at' => now()])->save();
            $sent++;
        }

        $this->info("{$sent} shoot(s) reminded for {$tomorrow->format('D j M')}.");

        return self::SUCCESS;
    }
}
