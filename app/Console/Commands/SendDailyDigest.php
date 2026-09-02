<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Shoot;
use App\Models\User;
use App\Notifications\DailyDigestReady;
use App\Support\ContentDashboard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * One push, every morning, to every admin: overdue invoices, shoots this
 * week, and how many accounts are behind their monthly content target --
 * the three questions DashboardController::actionItems() already answers
 * in detail, condensed to counts a push notification's body can actually
 * hold. Tapping it opens the real dashboard, which is where the detail
 * (which accounts, by how much, a suggested shoot-by date) already lives.
 *
 * Admins only, not every member of staff -- User::canSee() would also
 * reach a coordinator who has view access to Invoices without this being
 * their problem to act on every single morning; this is deliberately a
 * narrower list than most notifications in this app send to.
 */
class SendDailyDigest extends Command
{
    protected $signature = 'digest:send-daily';

    protected $description = 'Push the daily studio digest (overdue invoices, shoots this week, content pacing) to admins';

    public function handle(): int
    {
        $unpaid = Invoice::unpaid()->get();
        $overdue = $unpaid->filter(fn (Invoice $invoice) => $invoice->isOverdue());

        $shootsThisWeek = Shoot::query()
            ->where('status', '!=', Shoot::STATUS_CANCELLED)
            ->whereBetween('starts_at', [now()->startOfDay(), now()->addDays(7)->endOfDay()])
            ->count();

        $board = ContentDashboard::forMonth(now()->startOfMonth());
        $behindCount = collect($board['clients'])
            ->flatMap(fn (array $group) => $group['rows'])
            ->filter(fn (array $row) => $row['target'] !== null && $row['total'] < $row['target'])
            ->count();

        $summary = [
            'overdueCount' => $overdue->count(),
            'overdueAmount' => (float) $overdue->sum(fn (Invoice $invoice) => $invoice->balanceDue()),
            'shootsThisWeek' => $shootsThisWeek,
            'behindCount' => $behindCount,
        ];

        Notification::send(User::where('role', User::ROLE_ADMIN)->get(), new DailyDigestReady($summary));

        $this->info(sprintf(
            'Digest sent: %d overdue, %d shoot(s) this week, %d behind target.',
            $summary['overdueCount'], $summary['shootsThisWeek'], $summary['behindCount']
        ));

        return self::SUCCESS;
    }
}
