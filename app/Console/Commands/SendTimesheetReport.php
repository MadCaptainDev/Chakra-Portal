<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\WhatsappSender;
use App\Support\TimesheetDayReport;
use App\Support\WhatsappServiceWindow;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Nine at night, on each admin's WhatsApp: who entered today's timesheet and
 * who did not.
 *
 * Unlike the morning brief, a closed 24-hour window is not a reason to stay
 * quiet -- this is the one message the owner asked to always get. Inside the
 * window it is the full, readable list; outside it, the approved
 * timesheet_daily_v1 template carries the same facts on one screen. Until
 * Meta approves that template, an out-of-window send fails and says so.
 */
class SendTimesheetReport extends Command
{
    protected $signature = 'whatsapp:timesheet-report
        {--dry : Print the report instead of sending it}
        {--date= : A day other than today (YYYY-MM-DD)}';

    protected $description = "Send admins who entered today's timesheet and who did not, on WhatsApp";

    public function handle(): int
    {
        $day = $this->option('date') ? Carbon::parse($this->option('date')) : today();
        $report = TimesheetDayReport::for($day);

        if ($this->option('dry')) {
            $this->line($report->text());

            return self::SUCCESS;
        }

        $admins = User::query()
            ->where('role', User::ROLE_ADMIN)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->get();

        $sent = 0;
        $failed = 0;

        foreach ($admins as $admin) {
            $phone = WhatsappSender::normalise((string) $admin->phone);

            try {
                if (WhatsappServiceWindow::isOpen((string) $admin->phone)) {
                    WhatsappSender::make()->sendText($phone, $report->text());
                    $how = 'message';
                } else {
                    WhatsappSender::make()->sendTemplate(
                        $phone,
                        TimesheetDayReport::TEMPLATE,
                        'en_US',
                        $report->templateParameters(),
                    );
                    $how = 'template';
                }

                $this->info($admin->name.': sent as '.$how.'.');
                $sent++;
            } catch (Throwable $e) {
                // One unreachable number must not cost the others their report.
                Log::warning('Timesheet report failed for one admin.', [
                    'user_id' => $admin->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error($admin->name.': '.$e->getMessage());
                $failed++;
            }
        }

        $this->info('Timesheet report: '.$sent.' sent, '.$failed.' failed.');

        return $failed > 0 && $sent === 0 ? self::FAILURE : self::SUCCESS;
    }
}
