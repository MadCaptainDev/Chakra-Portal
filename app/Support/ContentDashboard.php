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

    /** Pipeline status groups for tracking progress. */
    public const STATUS_GROUPS = [
        'published' => ['Published'],
        'scheduled' => ['Scheduled'],
        'in_progress' => ['Video Ready', 'Under Review', 'Edit in Progress', 'To Be Edited', 'To Be Shooted'],
        'idea' => ['Idea'],
        'canceled' => ['Canceled'],
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
        $publishedCounts = self::countsByAccount($month);
        $plannedCounts = self::countsByAccountAllStatuses($month);
        $previous = self::countsByAccount($month->copy()->subMonthNoOverflow());
        $performance = self::performanceByAccount($month);
        $pipeline = self::pipelineForMonth($month);

        $accounts = ContentAccount::with(['client', 'ventures'])->targeted()->get()
            ->sortBy(fn (ContentAccount $a) => [$a->client?->name ?? '', $a->name])
            ->values();

        $rows = $accounts->map(function (ContentAccount $account) use ($publishedCounts, $plannedCounts, $previous, $performance) {
            $published = $publishedCounts[$account->id] ?? [];
            $planned = $plannedCounts[$account->id] ?? [];

            $types = collect(self::TARGETED)->map(function (string $label, string $source) use ($account, $published, $planned) {
                $actualPublished = $published[$source] ?? 0;
                $actualPlanned = $planned[$source] ?? 0;
                $target = $account->targetFor($source);

                return [
                    'label' => $label,
                    'actual' => $actualPublished,
                    'planned' => $actualPlanned,
                    'target' => $target,
                    'variance' => $target === null ? null : $actualPublished - $target,
                    'pct' => $target ? (int) round($actualPublished / $target * 100) : null,
                    'planned_pct' => $actualPlanned > 0 ? (int) round($actualPublished / $actualPlanned * 100) : null,
                ];
            })->all();

            $totalPublished = collect($types)->sum('actual');
            $totalPlanned = collect($types)->sum('planned');
            $target = $account->totalTarget();

            return [
                'account' => $account,
                'types' => $types,
                'stories' => $published[ContentItem::SOURCE_STORY] ?? 0,
                'total' => $totalPublished,
                'planned' => $totalPlanned,
                'target' => $target,
                'variance' => $target === null ? null : $totalPublished - $target,
                'pct' => $target ? (int) round($totalPublished / $target * 100) : null,
                'pipeline_pct' => $totalPlanned > 0 ? (int) round($totalPublished / $totalPlanned * 100) : null,
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
                'planned' => $rows->sum('planned'),
                'previous' => $rows->sum('previous'),
                'target' => $rows->whereNotNull('target')->sum('target') ?: null,
            ])
            ->values();

        return [
            'month' => $month->copy()->startOfMonth(),
            'clients' => $byClient,
            'grandTotal' => $rows->sum('total'),
            'grandPlanned' => $rows->sum('planned'),
            'grandPrevious' => $rows->sum('previous'),
            'grandTarget' => $rows->whereNotNull('target')->sum('target') ?: null,
            'pipeline' => $pipeline,
            'typeTotals' => collect(self::TARGETED)
                ->map(fn (string $label, string $source) => [
                    'label' => $label,
                    'actual' => $rows->sum(fn (array $r) => $r['types'][$source]['actual']),
                    'planned' => $rows->sum(fn (array $r) => $r['types'][$source]['planned']),
                    'target' => $rows->sum(fn (array $r) => $r['types'][$source]['target'] ?? 0) ?: null,
                ])->all(),
            'unmapped' => ContentAccount::unmappedVentures(),
            'unmappedThisMonth' => self::unmappedCountForMonth($month),
            'untargetedAccounts' => ContentAccount::query()->whereNotIn('id', $accounts->pluck('id'))->count(),
        ];
    }

    /**
     * Pipeline breakdown for the month — how many items in each status group.
     *
     * @return array<string, int>
     */
    public static function pipelineForMonth(Carbon $month): array
    {
        [$since, $until] = self::monthRange($month);

        $rows = ContentItem::query()
            ->selectRaw('status, count(*) as c')
            ->whereNotNull('published_date')
            ->whereBetween('published_date', [$since, $until])
            ->groupBy('status')
            ->pluck('c', 'status');

        $pipeline = [];
        foreach (self::STATUS_GROUPS as $group => $statuses) {
            $pipeline[$group] = $rows->only($statuses)->sum();
        }

        $pipeline['total'] = $pipeline['published'] + $pipeline['scheduled'] + $pipeline['in_progress'] + $pipeline['idea'];

        return $pipeline;
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
     * All non-canceled items for one month (the planned total), keyed account id => [source => count].
     *
     * @return array<int, array<string, int>>
     */
    private static function countsByAccountAllStatuses(Carbon $month): array
    {
        [$since, $until] = self::monthRange($month);

        $ventureToAccount = ContentAccountVenture::pluck('content_account_id', 'venture');

        $rows = ContentItem::query()
            ->selectRaw('venture, source, count(*) as c')
            ->whereNotNull('published_date')
            ->whereBetween('published_date', [$since, $until])
            ->whereNotNull('venture')
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'Canceled'))
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
     * The same per-type picture forMonth() builds, but for an arbitrary set
     * of accounts rather than every targeted one -- what a dashboard card
     * needs, where the accounts are whichever ones somebody pinned and an
     * account with no target is still worth a card.
     *
     * Query count does not grow with the number of accounts: the private
     * helpers below each answer for every account in one query, and this
     * picks out the ones asked for. Ten pinned cards cost the same as one.
     *
     * @param  Collection<int, ContentAccount>  $accounts
     * @return Collection<int, array<string, mixed>>
     */
    public static function forAccounts(Collection $accounts, Carbon $month): Collection
    {
        if ($accounts->isEmpty()) {
            return collect();
        }

        $published = self::countsByAccount($month);
        $planned = self::countsByAccountAllStatuses($month);
        $previous = self::countsByAccount($month->copy()->subMonthNoOverflow());
        $performance = self::performanceByAccount($month);
        $topPerformers = self::topPerformerByAccount($month);

        // Pace only means something while a month is still running. A
        // finished month is not "behind", it is simply what it was, and
        // colouring a closed month amber invites chasing work nobody can
        // still do.
        $isCurrentMonth = $month->isSameMonth(now());
        $pace = $isCurrentMonth ? self::monthElapsedFraction($month) : null;

        return $accounts->map(function (ContentAccount $account) use (
            $published, $planned, $previous, $performance, $topPerformers, $pace
        ) {
            $publishedCounts = $published[$account->id] ?? [];
            $plannedCounts = $planned[$account->id] ?? [];
            $previousCounts = $previous[$account->id] ?? [];

            $types = collect(self::TARGETED)->map(function (string $label, string $source) use (
                $account, $publishedCounts, $plannedCounts, $previousCounts, $pace
            ) {
                $actual = $publishedCounts[$source] ?? 0;
                $target = $account->targetFor($source);
                $was = $previousCounts[$source] ?? 0;

                return [
                    'label' => $label,
                    'actual' => $actual,
                    'planned' => $plannedCounts[$source] ?? 0,
                    'target' => $target,
                    'pct' => $target ? (int) round($actual / $target * 100) : null,
                    'delta' => $actual - $was,
                    'previous' => $was,
                    'pace' => self::paceVerdict($actual, $target, $pace),
                ];
            })->all();

            return [
                'account' => $account,
                'types' => $types,
                // Stories are counted but never targeted, so they carry no
                // pace or percentage -- just the number.
                'stories' => $publishedCounts[ContentItem::SOURCE_STORY] ?? 0,
                'total' => collect($types)->sum('actual'),
                'target' => $account->totalTarget(),
                'delta' => collect($types)->sum('delta'),
                'performance' => $performance[$account->id] ?? null,
                'top' => $topPerformers[$account->id] ?? null,
            ];
        })->values();
    }

    /**
     * How far through the month we are, 0..1 -- the yardstick a pace
     * verdict is measured against.
     */
    private static function monthElapsedFraction(Carbon $month): float
    {
        $days = $month->daysInMonth;

        return min(1.0, now()->day / max(1, $days));
    }

    /**
     * "on_track" / "behind", or null when the question does not apply --
     * no target set, or a month that has already finished.
     *
     * Deliberately two states, not a percentage: the only decision this
     * drives is "does someone need to shoot more this week", and a number
     * that is 91% of the way to a target invites arguing with the metric
     * instead of answering that.
     */
    private static function paceVerdict(int $actual, ?int $target, ?float $elapsed): ?string
    {
        if ($target === null || $target <= 0 || $elapsed === null) {
            return null;
        }

        return $actual >= $target * $elapsed ? 'on_track' : 'behind';
    }

    /**
     * The single best-performing published item per account this month, by
     * views -- the one line of "what actually worked" a card can carry.
     *
     * Views rather than likes: a reel's view count is the number the studio
     * is judged on, and likes on a small account swing on who happened to be
     * online. Accounts with no connected Instagram get nothing rather than a
     * zero, same convention as performanceByAccount().
     *
     * @return array<int, array{item: ContentItem, views: int}>
     */
    private static function topPerformerByAccount(Carbon $month): array
    {
        [$since, $until] = self::monthRange($month);

        $ventureToAccount = ContentAccountVenture::pluck('content_account_id', 'venture');

        $linked = ContentItem::query()
            ->whereNotNull('social_media_item_id')
            ->where('status', 'Published')
            ->whereNotNull('published_date')
            ->whereBetween('published_date', [$since, $until])
            ->get();

        if ($linked->isEmpty()) {
            return [];
        }

        $views = SocialInsight::query()
            ->selectRaw('social_media_item_id, SUM(value) as total')
            ->whereIn('social_media_item_id', $linked->pluck('social_media_item_id'))
            ->where('metric', 'views')
            ->groupBy('social_media_item_id')
            ->pluck('total', 'social_media_item_id');

        $best = [];

        foreach ($linked as $item) {
            $accountId = $ventureToAccount[$item->venture] ?? null;

            if ($accountId === null) {
                continue;
            }

            $itemViews = (int) ($views[$item->social_media_item_id] ?? 0);

            if (! isset($best[$accountId]) || $itemViews > $best[$accountId]['views']) {
                $best[$accountId] = ['item' => $item, 'views' => $itemViews];
            }
        }

        // An account whose every matched post has zero recorded views has
        // no "top" worth printing -- that is missing insight data, not a
        // winner that happened to score nothing.
        return array_filter($best, fn (array $b) => $b['views'] > 0);
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
     * One account's items for a month, with the real Instagram post each
     * turned into where one was matched -- the drill-down behind a dashboard row.
     *
     * @param  array<string>|null  $statuses  Filter to specific statuses, or null for all non-canceled
     * @return Collection<int, ContentItem>
     */
    public static function itemsFor(ContentAccount $account, Carbon $month, ?array $statuses = null): Collection
    {
        [$since, $until] = self::monthRange($month);

        return ContentItem::query()
            ->with('socialMediaItem.insights')
            ->whereIn('venture', $account->ventureNames() ?: ['__none__'])
            ->whereNotNull('published_date')
            ->whereBetween('published_date', [$since, $until])
            ->when($statuses !== null, fn ($q) => $q->whereIn('status', $statuses))
            ->when($statuses === null, fn ($q) => $q->where(fn ($q2) => $q2->whereNull('status')->orWhere('status', '!=', 'Canceled')))
            ->orderBy('published_date')
            ->orderBy('status')
            ->get();
    }

    /**
     * Pipeline breakdown for a single account.
     *
     * @return array<string, int>
     */
    public static function pipelineForAccount(ContentAccount $account, Carbon $month): array
    {
        [$since, $until] = self::monthRange($month);

        $rows = ContentItem::query()
            ->selectRaw('status, count(*) as c')
            ->whereIn('venture', $account->ventureNames() ?: ['__none__'])
            ->whereNotNull('published_date')
            ->whereBetween('published_date', [$since, $until])
            ->groupBy('status')
            ->pluck('c', 'status');

        $pipeline = [];
        foreach (self::STATUS_GROUPS as $group => $statuses) {
            $pipeline[$group] = $rows->only($statuses)->sum();
        }

        $pipeline['total'] = $pipeline['published'] + $pipeline['scheduled'] + $pipeline['in_progress'] + $pipeline['idea'];

        return $pipeline;
    }
}
