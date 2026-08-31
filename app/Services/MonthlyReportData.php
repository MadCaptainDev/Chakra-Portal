<?php

namespace App\Services;

use App\Models\Client;
use App\Models\SocialAccount;
use App\Models\SocialAudienceSnapshot;
use App\Services\Instagram\InstagramReportData;
use Illuminate\Support\Carbon;

/**
 * Everything a monthly Instagram report reads for one client, account and
 * month -- shared by MonthlyReportController's on-screen view and
 * MonthlyReportDocumentRenderer's PDF, so the two always agree.
 *
 * Reads ONLY local caches (social_insights, social_media_items,
 * social_audience_snapshots) plus the studio's own shoots -- see
 * InstagramInsights for why nothing here calls Instagram directly.
 */
class MonthlyReportData
{
    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function monthRange(Carbon $month): array
    {
        return [$month->copy()->startOfMonth()->startOfDay(), $month->copy()->endOfMonth()->endOfDay()];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forRange(Client $client, SocialAccount $account, Carbon $since, Carbon $until): array
    {
        // A generous limit, not the Insights screen's top-25-by-reach cap:
        // the format breakdown below needs every post in the month counted,
        // not just the best performers. The report table itself only shows
        // the top few.
        $content = InstagramReportData::contentPerformance($account, $since, $until, limit: 200);

        return [
            'overview' => InstagramReportData::overview($account, $since, $until),
            // 'follower_count', not 'reach' -- this was previously wired to
            // the reach trend while every consumer's own heading said
            // "Follower growth, day by day" (see MonthlyReportDocumentRenderer's
            // page1() and instagram/report.blade.php's x-charts.metric-trend).
            // trend() is metric-agnostic (it already powers the real reach
            // chart on the Insights screen), so this fixes the mismatch by
            // asking it for the metric the label actually promises rather
            // than adding new plumbing.
            'followerTrend' => InstagramReportData::trend($account, 'follower_count', $since, $until),
            'breakdown' => InstagramReportData::engagementBreakdown($account, $since, $until),
            'content' => $content,
            'formats' => InstagramReportData::formatBreakdown($content),
            'ageBreakdown' => self::snapshot($account, SocialAudienceSnapshot::DIMENSION_AGE_GENDER)?->ageBreakdown() ?? [],
            'genderBreakdown' => self::snapshot($account, SocialAudienceSnapshot::DIMENSION_AGE_GENDER)?->genderBreakdown() ?? [],
            'topCities' => self::snapshot($account, SocialAudienceSnapshot::DIMENSION_CITY)?->topCities() ?? [],
            'audienceSyncedAt' => self::snapshot($account, SocialAudienceSnapshot::DIMENSION_AGE_GENDER)?->fetched_at,
            'shoots' => $client->shoots()->whereBetween('starts_at', [$since, $until])->ordered()->get(),
        ];
    }

    private static function snapshot(SocialAccount $account, string $dimension): ?SocialAudienceSnapshot
    {
        return $account->audienceSnapshots()->dimension($dimension)->first();
    }
}
