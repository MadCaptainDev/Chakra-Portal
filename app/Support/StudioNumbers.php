<?php

namespace App\Support;

use App\Models\ContentItem;
use App\Models\PortfolioItem;
use App\Models\SocialAccount;
use App\Models\SocialMediaItem;
use App\Models\TimesheetEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The real data behind the homepage's "Where the hours go" (home/_hours)
 * and "Reports & analysis" (home/_services) -- read from the studio's own
 * records, then reduced to its shape (see get()) before it reaches a page.
 *
 * Shoot and edit time comes from timesheet task types. Posting, community
 * and reporting are logged as free-text "other" tasks, so they are
 * bucketed by keyword. Research is not on the clock, so it has no share.
 */
class StudioNumbers
{
    private const CACHE_KEY = 'home.studio-numbers';

    private const CACHE_SECONDS = 3 * 3600;

    /** Weeks of shoot days drawn in the Shoot calendar. */
    private const SHOOT_WEEKS = 26;

    /** Months of edit hours in the Edit bars. */
    private const EDIT_MONTHS = 6;

    /** Free-text "other" tasks that are posting or looking after comments and DMs. */
    private const POST_WORDS = ['post', 'upload', 'schedul', 'dm', 'cmt', 'comment', 'reply'];

    /** Free-text "other" tasks that are tracking and reporting. */
    private const TRACK_WORDS = ['excel', 'xl', 'insight', 'report', 'analytic', 'sheet'];

    /**
     * Accounts we took over part-way through their life: their posts from
     * before this date are someone else's work, so they are left out of
     * every number and grid the homepage shows. Keyed by Instagram username.
     */
    public const WORK_STARTED = [
        'thillaipetsclinic_' => '2026-08-15',
    ];

    /** Metrics the report adds up, as Instagram names them. */
    private const REPORT_METRICS = ['views', 'reach', 'likes', 'comments', 'shares', 'saved'];

    public static function workStartedFor(?string $username): ?string
    {
        return self::WORK_STARTED[$username] ?? null;
    }

    /**
     * What the homepage gets: the shape of the real records, never the
     * records. Hours, counts and view totals stay on the server -- the page
     * (and its source) only ever sees percentages, levels and multiples, so
     * the charts are true without publishing the studio's or its clients'
     * numbers.
     */
    public static function get(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => self::shapeOnly(self::build()));
    }

    /** $value as a whole percentage of $of, at least 1 when there is anything at all. */
    private static function pct(int|float $value, int|float $of): int
    {
        return $of > 0 && $value > 0 ? max(1, (int) round($value / $of * 100)) : 0;
    }

    private static function shapeOnly(array $raw): array
    {
        $hours = $raw['hours'];
        $clocked = array_sum($hours);
        $split = array_values(array_filter([
            ['stage' => 1, 'label' => 'Shoot', 'pct' => self::pct($hours['shoot'], $clocked)],
            ['stage' => 2, 'label' => 'Edit', 'pct' => self::pct($hours['edit'], $clocked)],
            ['stage' => 3, 'label' => 'Post & replies', 'pct' => self::pct($hours['post'], $clocked)],
            ['stage' => 4, 'label' => 'Reports', 'pct' => self::pct($hours['track'], $clocked)],
        ], fn ($row) => $row['pct'] > 0));

        $level = fn (float $h) => match (true) {
            $h < 0 => -1,
            $h == 0 => 0,
            $h < 3 => 1,
            $h < 6 => 2,
            $h < 9 => 3,
            default => 4,
        };
        $shoot = array_map(fn (array $week) => array_map(fn (array $day) => [
            'date' => $day['date'],
            'level' => $level($day['hours']),
        ], $week), $raw['shoot']['weeks']);

        $editMax = max(1, ...array_column($raw['edit'], 'hours'));
        $published = array_sum($raw['post']);
        $post = array_values(array_filter([
            ['label' => 'Reels', 'pct' => self::pct($raw['post']['reels'], $published)],
            ['label' => 'YouTube videos', 'pct' => self::pct($raw['post']['youtube'], $published)],
            ['label' => 'Posts', 'pct' => self::pct($raw['post']['posts'], $published)],
            ['label' => 'Stories', 'pct' => self::pct($raw['post']['stories'], $published)],
        ], fn ($row) => $row['pct'] > 0));

        $growth = $raw['track']['growth'];
        if ($growth) {
            $values = array_column($growth['points'], 'value');
            $max = max(1, ...$values);
            $growth = [
                'client' => $growth['client'],
                'multiple' => $values[0] > 0 ? intdiv(end($values), $values[0]) : null,
                'points' => array_map(fn (array $p) => ['date' => $p['date'], 'pct' => round($p['value'] / $max * 100, 1)], $growth['points']),
            ];
        }

        $report = $raw['report'];
        $months = $report['months'];
        $maxes = collect(self::REPORT_METRICS)->mapWithKeys(fn ($m) => [$m => max(1, ...array_column($months, $m))]);
        $first = $months[0]['views'] ?? 0;
        $last = end($months)['views'] ?? 0;
        $topViews = max(1, $report['top'][0]['views'] ?? 1);

        return [
            'split' => $split,
            'shoot' => $shoot,
            'edit' => array_map(fn (array $m) => ['label' => $m['label'], 'pct' => self::pct($m['hours'], $editMax)], $raw['edit']),
            'post' => $post,
            'growth' => $growth,
            'report' => [
                'months' => array_map(fn (array $m) => ['label' => $m['label']]
                    + $maxes->map(fn ($max, $metric) => self::pct($m[$metric], $max))->all(), $months),
                'viewsMultiple' => $first > 0 && $last >= $first * 2 ? intdiv($last, $first) : null,
                'engagement' => $report['engagement'],
                'top' => array_map(fn (array $t) => [
                    'username' => $t['username'],
                    'caption' => $t['caption'],
                    'pct' => self::pct($t['views'], $topViews),
                ], $report['top']),
            ],
        ];
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private static function build(): array
    {
        $minutes = fn (string $type) => (int) TimesheetEntry::where('task_type', $type)->sum('minutes');

        $other = TimesheetEntry::where('task_type', 'other')->get(['task', 'minutes']);
        $matches = fn (string $task, array $words) => collect($words)->contains(fn ($word) => str_contains($task, $word));
        $postMinutes = $minutes('posting');
        $trackMinutes = 0;

        foreach ($other as $entry) {
            $task = mb_strtolower((string) $entry->task);

            if ($matches($task, self::TRACK_WORDS)) {
                $trackMinutes += (int) $entry->minutes;
            } elseif ($matches($task, self::POST_WORDS)) {
                $postMinutes += (int) $entry->minutes;
            }
        }

        $published = ContentItem::query()->visible()
            ->where('status', 'Published')
            ->selectRaw('source, count(*) as n')
            ->groupBy('source')
            ->pluck('n', 'source');

        return [
            'hours' => [
                'shoot' => intdiv($minutes('shooting'), 60),
                'edit' => intdiv($minutes('editing'), 60),
                'post' => intdiv($postMinutes, 60),
                'track' => intdiv($trackMinutes, 60),
            ],
            'shoot' => self::shootCalendar(),
            'edit' => self::editMonths(),
            'post' => [
                'reels' => (int) ($published[ContentItem::SOURCE_REEL] ?? 0),
                'youtube' => (int) ($published[ContentItem::SOURCE_YOUTUBE] ?? 0),
                'posts' => (int) ($published[ContentItem::SOURCE_POST] ?? 0),
                'stories' => (int) ($published[ContentItem::SOURCE_STORY] ?? 0),
            ],
            'track' => ['growth' => self::growthCurve()],
            'report' => self::report(),
        ];
    }

    /**
     * The "Reports & analysis" tab: every post on the client accounts we
     * run, each at its latest synced reading -- totals, the last six
     * complete months by the month a post went up, and the top reels.
     *
     * @return array{posts: int, accounts: int, totals: array<string, int>, engagement: ?float, months: list<array<string, mixed>>, top: list<array<string, mixed>>}
     */
    private static function report(): array
    {
        $accounts = SocialAccount::query()->connected()->whereNotNull('client_id')->get(['id', 'username'])->keyBy('id');

        $items = SocialMediaItem::query()
            ->whereIn('social_account_id', $accounts->keys())
            ->get(['id', 'social_account_id', 'posted_at', 'caption', 'media_product_type'])
            ->filter(function (SocialMediaItem $item) use ($accounts) {
                $since = self::workStartedFor($accounts[$item->social_account_id]->username);

                return ! $since || ($item->posted_at && $item->posted_at->toDateString() >= $since);
            })
            ->keyBy('id');

        // The newest reading of each metric for each post: rows are read
        // oldest first, so a later one simply overwrites.
        $latest = [];
        foreach ($items->keys()->chunk(500) as $ids) {
            DB::table('social_insights')
                ->whereIn('social_media_item_id', $ids)
                ->whereIn('metric', self::REPORT_METRICS)
                ->orderBy('period_start')
                ->orderBy('id')
                ->select(['social_media_item_id', 'metric', 'value'])
                ->lazy()
                ->each(function ($row) use (&$latest) {
                    $latest[$row->social_media_item_id][$row->metric] = (int) $row->value;
                });
        }

        $sum = fn ($posts, string $metric) => (int) $posts->sum(fn (SocialMediaItem $item) => $latest[$item->id][$metric] ?? 0);

        $totals = collect(self::REPORT_METRICS)->mapWithKeys(fn ($metric) => [$metric => $sum($items, $metric)])->all();
        $interactions = $totals['likes'] + $totals['comments'] + $totals['shares'] + $totals['saved'];

        $months = [];
        for ($i = 6; $i >= 1; $i--) {
            $month = now()->startOfMonth()->subMonths($i);
            $inMonth = $items->filter(fn (SocialMediaItem $item) => $item->posted_at?->isSameMonth($month));
            $months[] = ['label' => $month->format('M')]
                + collect(self::REPORT_METRICS)->mapWithKeys(fn ($metric) => [$metric => $sum($inMonth, $metric)])->all();
        }

        $top = $items
            ->filter(fn (SocialMediaItem $item) => $item->isReel())
            ->sortByDesc(fn (SocialMediaItem $item) => $latest[$item->id]['views'] ?? 0)
            ->take(3)
            ->map(fn (SocialMediaItem $item) => [
                'username' => $accounts[$item->social_account_id]->username,
                'caption' => Str::limit(trim(strtok((string) $item->caption, "\n") ?: ''), 48),
                'views' => $latest[$item->id]['views'] ?? 0,
                'shares' => $latest[$item->id]['shares'] ?? 0,
                'saved' => $latest[$item->id]['saved'] ?? 0,
            ])
            ->values()
            ->all();

        return [
            'posts' => $items->count(),
            'accounts' => $accounts->count(),
            'totals' => $totals,
            'engagement' => $totals['reach'] > 0 ? round($interactions / $totals['reach'] * 100, 1) : null,
            'months' => $months,
            'top' => $top,
        ];
    }

    /**
     * Hours on set per day, for the last SHOOT_WEEKS weeks, Monday-first
     * columns -- the shape of a contribution calendar.
     *
     * @return array{days: int, weeks: list<list<array{date: string, hours: float}>>}
     */
    private static function shootCalendar(): array
    {
        $start = now()->startOfWeek()->subWeeks(self::SHOOT_WEEKS - 1);

        $byDay = TimesheetEntry::where('task_type', 'shooting')
            ->where('worked_on', '>=', $start->toDateString())
            ->selectRaw('worked_on, sum(minutes) as m')
            ->groupBy('worked_on')
            ->pluck('m', 'worked_on')
            ->mapWithKeys(fn ($m, $day) => [Carbon::parse($day)->toDateString() => (int) $m]);

        $weeks = [];
        for ($w = 0; $w < self::SHOOT_WEEKS; $w++) {
            $week = [];
            for ($d = 0; $d < 7; $d++) {
                $date = $start->copy()->addDays($w * 7 + $d);
                $week[] = [
                    'date' => $date->format('j M'),
                    'hours' => $date->isFuture() ? -1 : round(($byDay[$date->toDateString()] ?? 0) / 60, 1),
                ];
            }
            $weeks[] = $week;
        }

        return ['days' => $byDay->filter()->count(), 'weeks' => $weeks];
    }

    /**
     * Edit hours per calendar month, the last EDIT_MONTHS of them.
     *
     * @return list<array{label: string, hours: int}>
     */
    private static function editMonths(): array
    {
        $months = [];

        // From last month back: the month in progress would read as a slump.
        for ($i = self::EDIT_MONTHS; $i >= 1; $i--) {
            $month = now()->startOfMonth()->subMonths($i);
            $months[] = [
                'label' => $month->format('M'),
                'hours' => intdiv((int) TimesheetEntry::where('task_type', 'editing')
                    ->whereBetween('worked_on', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()])
                    ->sum('minutes'), 60),
            ];
        }

        return $months;
    }

    /**
     * The biggest published portfolio piece that has a synced history: its
     * views, sync by sync, for the touchable chart.
     *
     * @return array{title: string, client: ?string, points: list<array{date: string, value: int}>}|null
     */
    private static function growthCurve(): ?array
    {
        $pieces = PortfolioItem::published()
            ->whereNotNull('social_media_item_id')
            ->with(['socialMediaItem', 'client'])
            ->orderByDesc('views')
            ->limit(5)
            ->get();

        foreach ($pieces as $piece) {
            $points = $piece->socialMediaItem?->metricHistory('views') ?? [];

            if (count($points) >= 5) {
                return [
                    'title' => $piece->title,
                    'client' => $piece->clientLabel() ? Str::squish($piece->clientLabel()) : null,
                    'points' => array_map(fn (array $p) => [
                        'date' => Carbon::parse($p['date'])->format('j M'),
                        'value' => $p['value'],
                    ], $points),
                ];
            }
        }

        return null;
    }
}
