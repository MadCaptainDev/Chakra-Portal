<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\WhatsappSender;
use App\Support\AdminPortal;
use App\Support\WhatsappServiceWindow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The studio's morning, on the owner's phone, before anybody asks.
 *
 * The assistant answers questions; this answers the one that gets asked every
 * day anyway -- what is on, what came in, what is late, who did not log. Same
 * figures, same methods, no question required.
 *
 * Distinct from digest:send-daily, which pushes counts to the app for tapping
 * through to the dashboard. This is the version you read without opening
 * anything, which is the whole point of it arriving on WhatsApp.
 *
 * The 24-hour rule decides who actually gets it. Meta refuses free-form text
 * to anybody who has not written in a day, so a number outside its window is
 * skipped rather than attempted -- a failed send would cost a log line and
 * teach nobody anything. In practice the owner texts the assistant most days,
 * which holds the window open; the honest fix for the rest is an approved
 * template, which this studio does not have yet.
 */
class SendMorningBrief extends Command
{
    protected $signature = 'whatsapp:morning-brief {--dry : Print the brief instead of sending it}';

    protected $description = "Send each admin today's shoots, money and timesheet gaps on WhatsApp";

    public function handle(): int
    {
        $brief = AdminPortal::brief();

        if ($this->option('dry')) {
            $this->line($brief);

            return self::SUCCESS;
        }

        $admins = User::query()
            ->where('role', User::ROLE_ADMIN)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->get();

        $sent = 0;
        $skipped = 0;

        foreach ($admins as $admin) {
            if (! WhatsappServiceWindow::isOpen((string) $admin->phone)) {
                // Not a failure worth an error: they simply have not written
                // in a day, and Meta would refuse this before it left.
                $this->warn($admin->name.' is outside the 24-hour window — skipped.');
                $skipped++;

                continue;
            }

            try {
                WhatsappSender::make()->sendText(WhatsappSender::normalise((string) $admin->phone), $brief);
                $sent++;
            } catch (Throwable $e) {
                // One unreachable number must not cost the rest of them their
                // brief.
                Log::warning('Morning brief failed for one admin.', [
                    'user_id' => $admin->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error($admin->name.': '.$e->getMessage());
            }
        }

        $this->info('Morning brief: '.$sent.' sent, '.$skipped.' skipped.');

        return self::SUCCESS;
    }
}
