<?php

namespace App\Console\Commands;

use App\Models\MonthlyReportNote;
use App\Models\SocialAccount;
use App\Services\MonthlyReportData;
use App\Services\WhatsappSender;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * "Your report is ready" -- a nudge, never the document itself (contrast
 * MonthlyReportController::sendWhatsapp(), the staff-triggered send that
 * does attach the PDF). Just tells a client the report for the month that
 * just closed exists and to log in and look; nothing changes if they never
 * do.
 *
 * Scheduled for the 2nd of the month, not the 1st -- one day's slack for
 * the daily Instagram/Notion syncs to have actually run against the month
 * that just closed before this asks whether there is anything to report.
 */
class NotifyReportsReady extends Command
{
    protected $signature = 'reports:notify-ready';

    protected $description = "Tell clients last month's report is ready, once, over WhatsApp";

    public function handle(): int
    {
        $month = now()->subMonthNoOverflow()->startOfMonth();
        [$since, $until] = MonthlyReportData::monthRange($month);

        $accounts = SocialAccount::query()
            ->forPlatform(SocialAccount::PLATFORM_INSTAGRAM)
            ->connected()
            ->with('client')
            ->get();

        $notified = 0;
        $skipped = 0;

        foreach ($accounts as $account) {
            $client = $account->client;

            if (! $client || blank($client->phone)) {
                $skipped++;

                continue;
            }

            $note = MonthlyReportNote::forClientAndMonth($client, $month);

            // Already told them about this exact month -- a re-run (a
            // second scheduler tick, a manual retry) must not say it twice.
            if ($note->ready_notified_at) {
                continue;
            }

            // Nothing published that month is nothing to report -- a
            // client with a quiet month should not get a "your report is
            // ready" message pointing at an empty page.
            $hasContent = $client->contentItems()
                ->where('status', 'Published')
                ->whereBetween('published_date', [$since, $until])
                ->exists();

            if (! $hasContent) {
                $skipped++;

                continue;
            }

            try {
                WhatsappSender::make()->sendTemplate(
                    $client->phone,
                    MonthlyReportNote::WHATSAPP_TEMPLATE,
                    bodyParameters: [$client->name, $month->format('F Y')],
                );
            } catch (RuntimeException $e) {
                // Not marked ready_notified_at -- Meta's own reason (token
                // expired, outside whatever window applies, template not
                // yet approved) is logged and this client is simply tried
                // again on tomorrow's run rather than silently given up on.
                $this->error("{$client->name}: {$e->getMessage()}");

                continue;
            }

            $note->forceFill(['ready_notified_at' => now()])->save();
            $notified++;
        }

        $this->info("{$notified} client(s) notified, {$skipped} skipped, for {$month->format('F Y')}.");

        return self::SUCCESS;
    }
}
