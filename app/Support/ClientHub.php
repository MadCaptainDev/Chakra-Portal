<?php

namespace App\Support;

use App\Models\Client;
use App\Models\ContentAccount;
use App\Models\ContentItem;
use App\Models\Invoice;
use App\Models\Shoot;

/**
 * Everything about one client on one screen (the client page's Overview):
 * reels left and whether a shoot covers them, this month against target per
 * account, videos in the works, shoots, and money owed. Read-only; every
 * number comes from the module that owns it.
 */
class ClientHub
{
    /** @return array<string, mixed> */
    public static function for(Client $client, bool $withMoney): array
    {
        $monthStart = today()->startOfMonth();
        $accounts = $client->contentAccounts()->with('ventures')->orderBy('name')->get();

        // Reels out this month, by venture spelling, once for every account.
        $outByVenture = ContentItem::query()->visible()
            ->where('source', ContentItem::SOURCE_REEL)
            ->whereIn('venture', $client->contentVentures() ?: ['\0__none__'])
            ->whereIn('status', ContentDashboard::STATUS_GROUPS['published'])
            ->whereBetween('published_date', [$monthStart, today()->endOfDay()])
            ->selectRaw('venture, count(*) as n')->groupBy('venture')
            ->pluck('n', 'venture');

        $perAccount = $accounts->map(fn (ContentAccount $a) => [
            'name' => $a->name,
            'out' => (int) $a->ventures->sum(fn ($v) => $outByVenture[$v->venture] ?? 0),
            'target' => $a->targetFor(ContentItem::SOURCE_REEL),
        ]);

        $inWorks = $client->contentItems()
            ->whereIn('status', ContentDashboard::STATUS_GROUPS['in_progress'])
            ->orderByRaw('published_date is null')->orderBy('published_date')
            ->limit(8)
            ->get(['id', 'title', 'status', 'source', 'published_date', 'editor']);

        $shoots = $client->shoots()->where('status', '!=', Shoot::STATUS_CANCELLED)
            ->where('starts_at', '>=', today()->subDays(21))
            ->withCount('videos')
            ->orderByDesc('starts_at')->limit(4)->get();

        $unpaid = $withMoney
            ? $client->invoices()->where('status', Invoice::STATUS_UNPAID)->with('payments')->orderBy('invoice_date')->get()
            : collect();

        return [
            'forecast' => ContentForecast::forClient($client),
            'month' => [
                'out' => (int) $outByVenture->sum(),
                'target' => (int) $perAccount->sum(fn ($a) => $a['target'] ?? 0),
            ],
            'accounts' => $perAccount,
            'inWorksCount' => $client->contentItems()->whereIn('status', ContentDashboard::STATUS_GROUPS['in_progress'])->count(),
            'inWorks' => $inWorks,
            'shoots' => $shoots,
            'unpaid' => $unpaid,
            'unpaidTotal' => (float) $unpaid->sum(fn (Invoice $i) => $i->balanceDue()),
            'ventures' => $client->contentVentures(),
        ];
    }

    /** "₹1,27,350" -- Indian grouping, no paise. */
    public static function rupees(float $amount): string
    {
        $n = (string) (int) round($amount);
        $last3 = substr($n, -3);
        $rest = substr($n, 0, -3);

        return '₹'.($rest !== '' ? preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest).','.$last3 : $last3);
    }
}
