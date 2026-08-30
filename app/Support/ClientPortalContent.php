<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Shoot;
use App\Models\SocialAccount;
use App\Models\WhatsappFlowSession;
use App\Services\Instagram\InstagramReportData;
use App\Services\MonthlyReportData;
use App\Services\WhatsappSender;
use Illuminate\Support\Carbon;

/**
 * Client-specific WhatsApp content for automation nodes.
 */
class ClientPortalContent
{
    public static function clientForSession(WhatsappFlowSession $session): ?Client
    {
        $clientId = data_get($session->variables, 'client.id');

        return $clientId ? Client::find($clientId) : Client::findForWhatsappPortal($session->wa_id);
    }

    public static function invoices(Client $client): string
    {
        $invoices = Invoice::query()
            ->where('client_id', $client->id)
            ->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PAID])
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        if ($invoices->isEmpty()) {
            return "You don't have any issued invoices yet.";
        }

        $lines = ['Your recent invoices:', ''];

        foreach ($invoices as $invoice) {
            $status = $invoice->status === Invoice::STATUS_PAID ? 'Paid' : 'Unpaid';
            $lines[] = "• {$invoice->invoice_number} — ₹".number_format((float) $invoice->total, 2)." — {$status}";

            if ($invoice->status === Invoice::STATUS_UNPAID || $invoice->public_token) {
                $lines[] = '  '.$invoice->publicUrl();
            }
        }

        return implode("\n", $lines);
    }

    public static function monthlyReport(Client $client): string
    {
        $account = $client->socialAccounts()
            ->where('platform', SocialAccount::PLATFORM_INSTAGRAM)
            ->where('status', SocialAccount::STATUS_CONNECTED)
            ->first();

        if ($account === null) {
            return "Your Instagram account isn't connected yet, so there is no monthly report to share here.";
        }

        $month = now()->subMonthNoOverflow()->startOfMonth();
        [$since, $until] = MonthlyReportData::monthRange($month);
        $overview = InstagramReportData::overview($account, $since, $until);

        $lines = [
            "Instagram report — {$month->format('F Y')}",
            '',
            'Followers: '.number_format((int) ($overview['followers'] ?? 0)),
            'Reach: '.number_format((int) ($overview['reach'] ?? 0)),
            'Views: '.number_format((int) ($overview['views'] ?? 0)),
            'Engagement: '.number_format((int) ($overview['engagement'] ?? 0)),
        ];

        $published = $client->contentItems()
            ->where('status', 'Published')
            ->whereBetween('published_date', [$since, $until])
            ->count();

        if ($published > 0) {
            $lines[] = 'Content published: '.number_format($published);
        }

        return implode("\n", $lines);
    }

    public static function upcomingShoots(Client $client): string
    {
        $shoots = Shoot::query()
            ->where('client_id', $client->id)
            ->upcoming()
            ->ordered()
            ->limit(5)
            ->get();

        if ($shoots->isEmpty()) {
            return 'Nothing is scheduled right now.';
        }

        $lines = ['Upcoming shoots:', ''];

        foreach ($shoots as $shoot) {
            $line = "• {$shoot->title} — ".$shoot->starts_at->format('j M Y, g:i A');
            if ($shoot->location) {
                $line .= " · {$shoot->location}";
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    public static function sendToSession(WhatsappFlowSession $session, string $body): void
    {
        WhatsappSender::make()->sendText($session->wa_id, $body);
    }
}
