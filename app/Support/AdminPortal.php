<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Shoot;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Models\WhatsappFlowSession;
use App\Services\WhatsappSender;
use Illuminate\Support\Carbon;

/**
 * The owner's cut of the WhatsApp portal: the four questions somebody
 * running the studio asks when they are not at a laptop.
 *
 * The third of three portals, and the narrowest on purpose. ClientPortalContent
 * answers a client about their own account; CrewPortal answers a crew member
 * about their own day; this answers the person who carries the whole business
 * -- so it is the only one that reads across every client's money, and the only
 * one gated on isAdmin() rather than merely being staff.
 *
 * Every figure here is computed the same way the admin dashboard computes it
 * (see DashboardController::index). That is deliberate: a number that reads
 * differently on WhatsApp than on the dashboard is worse than no number, because
 * the person now has to work out which one lied.
 */
class AdminPortal
{
    /**
     * The admin behind this session, or null.
     *
     * Reuses the crew lookup (last-ten-digit phone match, clients excluded)
     * and then narrows to admins. An employee reaching an admin flow gets the
     * same flat refusal a stranger does -- their own numbers are the ones
     * CrewPortal answers.
     */
    public static function userForSession(WhatsappFlowSession $session): ?User
    {
        $userId = data_get($session->variables, 'crew.id');

        $user = $userId
            ? User::query()->staff()->find($userId)
            : User::findForWhatsappCrew($session->wa_id);

        return $user?->isAdmin() ? $user : null;
    }

    /** Collected this month, and what is still owed across every client. */
    public static function money(): string
    {
        $month = now()->startOfMonth();

        $collected = (float) Payment::whereBetween('paid_on', [$month, now()->endOfMonth()])->sum('amount');

        $unpaid = Invoice::unpaid()->with('payments')->get();
        $outstanding = $unpaid->sum(fn (Invoice $invoice) => $invoice->balanceDue());

        return implode("\n", [
            $month->format('F').' so far',
            '',
            'Collected: '.self::money_($collected),
            'Outstanding: '.self::money_($outstanding).' across '.$unpaid->count().' '.str('invoice')->plural($unpaid->count()),
        ]);
    }

    /** What is past its due date, worst first. */
    public static function overdue(): string
    {
        $overdue = Invoice::unpaid()
            ->with(['client', 'payments'])
            ->get()
            ->filter(fn (Invoice $invoice) => $invoice->isOverdue())
            ->sortBy('due_date')
            ->values();

        if ($overdue->isEmpty()) {
            return 'Nothing overdue. Every unpaid invoice is still inside its terms.';
        }

        $total = $overdue->sum(fn (Invoice $invoice) => $invoice->balanceDue());

        $lines = [
            $overdue->count().' overdue — '.self::money_($total),
            '',
        ];

        foreach ($overdue->take(8) as $invoice) {
            $days = (int) $invoice->due_date?->diffInDays(now());
            $lines[] = '• '.($invoice->client?->name ?? 'No client').' — '.self::money_($invoice->balanceDue())
                .' ('.$days.'d)';
        }

        if ($overdue->count() > 8) {
            $lines[] = '…and '.($overdue->count() - 8).' more.';
        }

        return implode("\n", $lines);
    }

    /** Everything shooting today, and who is on it. */
    public static function todaysShoots(): string
    {
        $shoots = Shoot::query()
            ->whereBetween('starts_at', [today()->startOfDay(), today()->endOfDay()])
            ->with('crew.user', 'client')
            ->orderBy('starts_at')
            ->get();

        if ($shoots->isEmpty()) {
            return 'Nothing shooting today.';
        }

        $lines = ['Today — '.$shoots->count().' '.str('shoot')->plural($shoots->count()), ''];

        foreach ($shoots as $shoot) {
            $lines[] = '• '.$shoot->title.self::whenSuffix($shoot);

            if ($shoot->location) {
                $lines[] = '  '.$shoot->location;
            }

            $crew = $shoot->crew->map(fn ($member) => $member->user?->name)->filter();
            $lines[] = '  Crew: '.($crew->isEmpty() ? 'nobody assigned' : $crew->implode(', '));
            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }

    /**
     * Who has not logged yesterday.
     *
     * Yesterday, not today: today is not late until the day is out, and a
     * list that names everybody every morning is one nobody reads.
     */
    public static function timesheetGaps(): string
    {
        $day = today()->subDay();

        $logged = TimesheetEntry::query()
            ->whereDate('worked_on', $day->toDateString())
            ->counted()
            ->pluck('user_id')
            ->unique();

        $missing = User::query()->whoLogWork()->orderBy('name')->get()
            ->reject(fn (User $user) => $logged->contains($user->id));

        if ($missing->isEmpty()) {
            return 'Everyone logged '.$day->format('D j M').'.';
        }

        return implode("\n", array_merge(
            [$missing->count().' did not log '.$day->format('D j M').':', ''],
            $missing->map(fn (User $user) => '• '.$user->name)->all(),
        ));
    }

    public static function sendToSession(WhatsappFlowSession $session, string $body): void
    {
        WhatsappSender::make()->sendText($session->wa_id, $body);
    }

    /** Whole rupees: a figure read on a phone does not want paise. */
    private static function money_(float $amount): string
    {
        return '₹'.number_format($amount, 0);
    }

    /**
     * Midnight means Notion synced a date with no time on it (see
     * CrewPortal::callTimeLabel for the same rule) -- printing "12:00 AM"
     * would read as a genuine small-hours call.
     */
    private static function whenSuffix(Shoot $shoot): string
    {
        $startsAt = $shoot->starts_at;

        if ($startsAt === null || ($startsAt->hour === 0 && $startsAt->minute === 0)) {
            return '';
        }

        return ' — '.$startsAt->format('g:i A');
    }
}
