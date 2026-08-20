<?php

namespace App\Support;

use App\Models\ContentAccount;
use App\Models\ContentAccountVenture;
use App\Models\ContentItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What each content account actually published in a month, against what it
 * was supposed to.
 *
 * Counts only items Notion says are Published AND that carry a
 * published_date -- an item marked Published with no date cannot be
 * attributed to a month at all, and guessing (falling back to created or
 * synced time) would quietly credit it to the wrong one. On the real data
 * every Published row has a date, so this excludes nothing today; it is
 * here so that a future dateless row is visibly absent rather than
 * invisibly misfiled.
 *
 * Attribution is by explicit venture mapping only (content_account_ventures)
 * -- see that migration for why fuzzy matching is not trusted here.
 */
class ContentDashboard
{
    /** Sources counted, in the order the dashboard shows them. */
    public const SOURCES = [
        ContentItem::SOURCE_REEL => 'Reels',
        ContentItem::SOURCE_YOUTUBE => 'YouTube',
        ContentItem::SOURCE_POST => 'Posts',
        ContentItem::SOURCE_STORY => 'Stories',
    ];

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function monthRange(Carbon $month): array
    {
        return [$month->copy()->startOfMonth()->startOfDay(), $month->copy()->endOfMonth()->endOfDay()];
    }

    /**
     * The whole dashboard for one month.
     *
     * @return array<string, mixed>
     */
    public static function forMonth(Carbon $month): array
    {
        $current = self::countsByAccount($month);
        $previous = self::countsByAccount($month->copy()->subMonthNoOverflow());

        $accounts = ContentAccount::with(['client', 'ventures'])->get()
            ->sortBy(fn (ContentAccount $a) => [$a->client?->name ?? '', $a->name])
            ->values();

        $rows = $accounts->map(function (ContentAccount $account) use ($current, $previous) {
            $counts = $current[$account->id] ?? [];
            $total = array_sum($counts);

            return [
                'account' => $account,
                'counts' => $counts,
                'total' => $total,
                'previous' => array_sum($previous[$account->id] ?? []),
                'target' => $account->monthly_target,
                // null target means "nobody has said what good looks like
                // here yet" -- shown as an em dash, never as 0% of 0.
                'variance' => $account->monthly_target === null ? null : $total - $account->monthly_target,
                'pct' => $account->monthly_target ? (int) round($total / $account->monthly_target * 100) : null,
            ];
        });

        $byClient = $rows->groupBy(fn (array $row) => $row['account']->client_id)
            ->map(fn (Collection $rows) => [
                'client' => $rows->first()['account']->client,
                'rows' => $rows->values(),
                'total' => $rows->sum('total'),
                'previous' => $rows->sum('previous'),
                'target' => $rows->whereNotNull('target')->sum('target') ?: null,
            ])
            ->values();

        return [
            'month' => $month->copy()->startOfMonth(),
            'clients' => $byClient,
            'grandTotal' => $rows->sum('total'),
            'grandPrevious' => $rows->sum('previous'),
            'grandTarget' => $rows->whereNotNull('target')->sum('target') ?: null,
            'sourceTotals' => collect(self::SOURCES)
                ->mapWithKeys(fn (string $label, string $source) => [
                    $source => $rows->sum(fn (array $r) => $r['counts'][$source] ?? 0),
                ])->all(),
            'unmapped' => ContentAccount::unmappedVentures(),
            'unmappedThisMonth' => self::unmappedCountForMonth($month),
        ];
    }

    /**
     * Published counts for one month, keyed account id => [source => count].
     *
     * One query for the whole board: grouped by venture and source, then
     * folded onto accounts in PHP. The alternative -- a query per account --
     * is twenty round trips to say the same thing.
     *
     * @return array<int, array<string, int>>
     */
    private static function countsByAccount(Carbon $month): array
    {
        [$since, $until] = self::monthRange($month);

        $ventureToAccount = ContentAccountVenture::pluck('content_account_id', 'venture');

        $rows = ContentItem::query()
            ->selectRaw('venture, source, count(*) as c')
            ->where('status', 'Published')
            ->whereNotNull('published_date')
            ->whereBetween('published_date', [$since, $until])
            ->whereNotNull('venture')
            ->groupBy('venture', 'source')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $accountId = $ventureToAccount[$row->venture] ?? null;

            if ($accountId === null) {
                continue;
            }

            $counts[$accountId][$row->source] = ($counts[$accountId][$row->source] ?? 0) + (int) $row->c;
        }

        return $counts;
    }

    /**
     * How many published items this month belong to no account at all --
     * the number that makes an unmapped-venture warning worth acting on
     * rather than ignoring.
     */
    private static function unmappedCountForMonth(Carbon $month): int
    {
        [$since, $until] = self::monthRange($month);
        $mapped = ContentAccountVenture::pluck('venture')->all();

        return ContentItem::query()
            ->where('status', 'Published')
            ->whereNotNull('published_date')
            ->whereBetween('published_date', [$since, $until])
            ->when($mapped !== [], fn ($q) => $q->whereNotIn('venture', $mapped))
            ->count();
    }

    /**
     * Months that actually have published content, newest first -- what the
     * month picker offers. Built from the data rather than a fixed range so
     * it never offers an empty month or hides a real one.
     *
     * @return Collection<int, Carbon>
     */
    public static function availableMonths(): Collection
    {
        $min = ContentItem::whereNotNull('published_date')->min('published_date');
        $max = ContentItem::whereNotNull('published_date')->max('published_date');

        if (! $min || ! $max) {
            return collect([now()->startOfMonth()]);
        }

        $months = collect();
        $cursor = Carbon::parse($max)->startOfMonth();
        $floor = Carbon::parse($min)->startOfMonth();

        while ($cursor->gte($floor)) {
            $months->push($cursor->copy());
            $cursor->subMonthNoOverflow();
        }

        return $months;
    }
}
