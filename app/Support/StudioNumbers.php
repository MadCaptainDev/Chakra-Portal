<?php

namespace App\Support;

use App\Models\ClientBrief;
use App\Models\CompetitorReel;
use App\Models\ContentItem;
use App\Models\PortfolioItem;
use App\Models\Script;
use App\Models\SocialAccount;
use App\Models\SocialInsight;
use App\Models\TimesheetEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * "Where the hours go" on the homepage (home/_hours): what the studio
 * actually spends its time on, read from its own records rather than
 * written as marketing copy.
 *
 * Shoot and edit are measured in hours because that is how the team logs
 * them (timesheet task types). Posting, community and reporting are logged
 * as free-text "other" tasks, so they are bucketed by keyword. Research is
 * not on the clock at all -- it shows up as scripts, briefs and competitor
 * reels instead, and the page says so rather than inventing hours for it.
 *
 * Every figure is floored, never rounded up, like HomePage::stats().
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

    public static function get(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => self::build());
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

        $since = TimesheetEntry::min('worked_on');

        return [
            'since' => $since ? Carbon::parse($since)->format('F Y') : null,
            'hours' => [
                'shoot' => intdiv($minutes('shooting'), 60),
                'edit' => intdiv($minutes('editing'), 60),
                'post' => intdiv($postMinutes, 60),
                'track' => intdiv($trackMinutes, 60),
            ],
            'research' => [
                'scripts' => Script::count(),
                'briefs' => ClientBrief::count(),
                'competitorReels' => CompetitorReel::count(),
            ],
            'shoot' => self::shootCalendar(),
            'edit' => self::editMonths(),
            'post' => [
                'reels' => (int) ($published[ContentItem::SOURCE_REEL] ?? 0),
                'youtube' => (int) ($published[ContentItem::SOURCE_YOUTUBE] ?? 0),
                'posts' => (int) ($published[ContentItem::SOURCE_POST] ?? 0),
                'stories' => (int) ($published[ContentItem::SOURCE_STORY] ?? 0),
            ],
            'track' => [
                'readings' => SocialInsight::count(),
                'accounts' => SocialAccount::query()->connected()->count(),
                'growth' => self::growthCurve(),
            ],
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
