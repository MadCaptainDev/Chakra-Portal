<?php

namespace App\Support;

use App\Models\ContentAccount;
use App\Models\ContentAccountVenture;
use App\Models\ContentItem;
use App\Models\SocialInsight;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What each content account published in a month, per content type,
 * against what it committed to -- plus how the published work actually
 * performed on Instagram where that can be known.
 *
 * Counts only items Notion says are Published AND that carry a
 * published_date: an item marked Published with no date cannot be
 * attributed to a month, and falling back to created or synced time would
 * quietly credit it to the wrong one.
 *
 * Attribution is by explicit venture mapping only (content_account_ventures)
 * -- see that migration for why fuzzy matching is not trusted here.
 *
 * Only accounts carrying at least one target appear. An account with no
 * target has nothing to be compared against, so a row for it would be
 * numbers with no verdict; those live on the Content Accounts screen
 * instead.
 */
class ContentDashboard
{
    /** Types that carry a target, in the order the dashboard shows them. */
    public const TARGETED = ContentAccount::TARGETABLE;

    /** Counted and shown, but never targeted -- nobody plans stories monthly. */
    public const UNTARGETED = [ContentItem::SOURCE_STORY => 'Stories'];

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
        $performance = self::performanceByAccount($month);

        $accounts = ContentAccount::with(['client', 'ventures'])->targeted()->get()
            ->sortBy(fn (ContentAccount $a) => [$a->client?->name ?? '', $a->name])
            ->values();

        $rows = $accounts->map(function (ContentAccount $account) use ($current, $previous, $performance) {
            $counts = $current[$account->id] ?? [];

            $types = collect(self::TARGETED)->map(function (string $label, string $source) use ($account, $counts) {
                $actual = $counts[$source] ?? 0;
                $target = $account->targetFor($source);

                return [
                    'label' => $label,
                    'actual' => $actual,
                    'target' => $target,
                    'variance' => $target === null ? null : $actual - $target,
                    'pct' => $target ? (int) round($actual / $target * 100) : null,
                ];
            })->all();

            // Total is the targeted types only, so it is comparable with
            // totalTarget. Stories are real output but nothing promised
            // them, and folding them in would let a good story month hide
            // a missed reel target.
            $total = collect($types)->sum('actual');
            $target = $account->totalTarget();

            return [
                'account' => $account,
                'types' => $types,
                'stories' => $counts[ContentItem::SOURCE_STORY] ?? 0,
                'total' => $total,
                'target' => $target,
                'variance' => $target === null ? null : $total - $target,
                'pct' => $target ? (int) round($total / $target * 100) : null,
                'previous' => collect(array_keys(self::TARGETED))
                    ->sum(fn (string $s) => $previous[$account->id][$s] ?? 0),
                'performance' => $performance[$account->id] ?? null,
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
            'typeTotals' => collect(self::TARGETED)
                ->map(fn (string $label, string $source) => [
                    'label' => $label,
                    'actual' => $rows->sum(fn (array $r) => $r['types'][$source]['actual']),
                    'target' => $rows->sum(fn (array $r) => $r['types'][$source]['target'] ?? 0) ?: null,
                ])->all(),
            'unmapped' => ContentAccount::unmappedVentures(),
            'unmappedThisMonth' => self::unmappedCountForMonth($month),
            'untargetedAccounts' => ContentAccount::query()->whereNotIn('id', $accounts->pluck('id'))->count(),
        ];
    }

    /**
     * Published counts for one month, keyed account id => [source => count].
     *
     * One query for the whole board: grouped by venture and source, then
     * folded onto accounts in PHP. The alternative -- a query per account
     * per type -- is dozens of round trips to say the same thing.
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
     * Real Instagram performance for the month's published items, for the
     * accounts where that is knowable.
     *
     * Only items matched to an actual Instagram post contribute -- see
     * InstagramContentMatcher. Most accounts have no connected Instagram
     * and get null, which the view shows as a dash rather than a zero: no
     * data and no reach are different answers.
     *
     * @return array<int, array{items: int, reach: int, views: int, likes: int}>
     */
    private static function performanceByAccount(Carbon $month): array
    {
        [$since, $until] = self::monthRange($month);

        $ventureToAccount = ContentAccountVenture::pluck('content_account_id', 'venture');

        $linked = ContentItem::query()
            ->select(['venture', 'social_media_item_id'])
            ->whereNotNull('social_media_item_id')
            ->where('status', 'Published')
            ->whereNotNull('published_date')
            ->whereBetween('published_date', [$since, $until])
            ->get();

        if ($linked->isEmpty()) {
            return [];
        }

        $metrics = SocialInsight::query()
            ->selectRaw('social_media_item_id, metric, SUM(value) as total')
            ->whereIn('social_media_item_id', $linked->pluck('social_media_item_id'))
            ->whereIn('metric', ['reach', 'views', 'likes'])
            ->groupBy('social_media_item_id', 'metric')
            ->get()
            ->groupBy('social_media_item_id');

        $out = [];

        foreach ($linked as $item) {
            $accountId = $ventureToAccount[$item->venture] ?? null;

            if ($accountId === null) {
                continue;
            }

            $out[$accountId] ??= ['items' => 0, 'reach' => 0, 'views' => 0, 'likes' => 0];
            $out[$accountId]['items']++;

            foreach ($metrics->get($item->social_media_item_id, collect()) as $metric) {
                $out[$accountId][$metric->metric] += (int) $metric->total;
            }
        }

        return $out;
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

    /**
     * One account's published items for a month, with the real Instagram
     * post each turned into where one was matched -- the drill-down behind
     * a dashboard row.
     *
     * @return Collection<int, ContentItem>
     */
    public static function itemsFor(ContentAccount $account, Carbon $month): Collection
    {
        [$since, $until] = self::monthRange($month);

        return ContentItem::query()
            ->with('socialMediaItem.insights')
            ->whereIn('venture', $account->ventureNames() ?: ['__none__'])
            ->where('status', 'Published')
            ->whereNotNull('published_date')
            ->whereBetween('published_date', [$since, $until])
            ->orderBy('published_date')
            ->get();
    }
}
