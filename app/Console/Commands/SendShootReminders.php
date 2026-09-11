<?php

namespace App\Console\Commands;

use App\Models\Shoot;
use App\Models\ShootCrew;
use App\Notifications\ShootReminderDue;
use App\Services\WhatsappSender;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

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
                    $this->sendWhatsapp($shoot, $crew);
                }
            }

            $shoot->forceFill(['reminder_sent_at' => now()])->save();
            $sent++;
        }

        $this->info("{$sent} shoot(s) reminded for {$tomorrow->format('D j M')}.");

        return self::SUCCESS;
    }

    /**
     * Same information as the push, over WhatsApp too -- skipped quietly
     * when the crew member has no phone on file, same as no push token
     * quietly means no push. One bad number or an unapproved template must
     * not stop the rest of the crew from being reminded, so failures are
     * logged rather than thrown.
     */
    private function sendWhatsapp(Shoot $shoot, ShootCrew $crew): void
    {
        if (! $crew->user || blank($crew->user->phone)) {
            return;
        }

        $callTime = null;
        if ($crew->call_time) {
            try {
                $callTime = Carbon::parse($crew->call_time)->format('g:ia');
            } catch (Throwable) {
                $callTime = null;
            }
        }

        // The template's third {{n}} is never blank -- an approved
        // template's variables aren't optional at send time, so a shoot
        // with neither a call time nor a location still gets a real
        // sentence here rather than an empty string.
        $detail = collect([
            $callTime ? "Call {$callTime}" : null,
            $shoot->location,
        ])->filter()->implode(' · ');

        $detail = $detail !== '' ? "{$detail}." : 'Check the call sheet for details.';

        try {
            WhatsappSender::make()->sendTemplate(
                to: $crew->user->phone,
                template: Shoot::WHATSAPP_TEMPLATE_REMINDER,
                bodyParameters: [$shoot->title, $shoot->starts_at->format('D j M'), $detail],
            );
        } catch (RuntimeException $e) {
            Log::error('Shoot reminder WhatsApp send failed.', [
                'shoot_id' => $shoot->id,
                'user_id' => $crew->user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
