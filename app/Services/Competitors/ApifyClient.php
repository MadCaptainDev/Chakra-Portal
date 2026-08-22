<?php

namespace App\Services\Competitors;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The low-level conversation with Apify's Instagram scraper actor
 * (`apify~instagram-scraper`), ported from the reference tool's `apify.ts`.
 *
 * One run-sync-get-dataset-items call does the whole job (Apify runs the
 * scrape and hands back the finished dataset in one response, no separate
 * poll-for-completion step) -- but a scrape genuinely can take longer than a
 * typical API call, hence the longer timeout than InstagramGraph's 15s.
 */
class ApifyClient
{
    private const RUN_URL = 'https://api.apify.com/v2/acts/apify~instagram-scraper/run-sync-get-dataset-items';

    /** A scrape run itself can take a while; this is not a quick lookup. */
    private const TIMEOUT_SECONDS = 90;

    public function __construct(private readonly string $token) {}

    public static function make(): self
    {
        return new self((string) \App\Models\CompetitorSetting::current()->apify_token);
    }

    /**
     * Recent reels/posts for one account, newest matching `onlyPostsNewerThan`
     * first. `resultsType: stories` is the reference tool's own choice for
     * "the feed of individual posts," not Instagram Stories.
     *
     * @return list<array<string, mixed>>
     */
    public function scrapeReels(string $username, int $maxVideos, int $nDays): array
    {
        $response = $this->post([
            'addParentData' => false,
            'directUrls' => ["https://www.instagram.com/{$username}/"],
            'enhanceUserSearchWithFacebookPage' => false,
            'isUserReelFeedURL' => false,
            'isUserTaggedFeedURL' => false,
            'onlyPostsNewerThan' => now()->subDays($nDays)->toDateString(),
            'resultsLimit' => $maxVideos,
            'resultsType' => 'stories',
        ]);

        return $this->result($response, 'scrapeReels');
    }

    /**
     * Profile picture + follower count, from a single `details`-mode result.
     *
     * @return array{profilePicUrl: ?string, followersCount: ?int}
     */
    public function scrapeProfile(string $username): array
    {
        $response = $this->post([
            'directUrls' => ["https://www.instagram.com/{$username}/"],
            'resultsType' => 'details',
            'resultsLimit' => 1,
        ]);

        $profile = $this->result($response, 'scrapeProfile')[0] ?? [];

        return [
            'profilePicUrl' => $profile['profilePicUrl'] ?? null,
            'followersCount' => $profile['followersCount'] ?? null,
        ];
    }

    /**
     * Up to 100 posts from the last 30 days, for computing that account's own
     * average views -- the bar CompetitorReel::isViral() compares against.
     *
     * @return list<array<string, mixed>>
     */
    public function scrapeRecentPosts(string $username): array
    {
        $response = $this->post([
            'addParentData' => false,
            'directUrls' => ["https://www.instagram.com/{$username}/"],
            'resultsType' => 'stories',
            'resultsLimit' => 100,
            'onlyPostsNewerThan' => now()->subDays(30)->toDateString(),
        ]);

        return $this->result($response, 'scrapeRecentPosts');
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function post(array $body): Response
    {
        return Http::timeout(self::TIMEOUT_SECONDS)
            ->asJson()
            ->post(self::RUN_URL.'?token='.$this->token, $body);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function result(Response $response, string $context): array
    {
        if ($response->failed()) {
            $this->throwFrom($response, $context);
        }

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * Apify's own error text, verbatim -- same reasoning as
     * InstagramGraph::throwFrom(): their message usually names its own fix.
     * Never logs the token, which sits in the URL this call was made against.
     */
    private function throwFrom(Response $response, string $context): never
    {
        $message = "Apify error {$response->status()} for {$context}: ".$response->body();

        Log::error('Apify call failed.', [
            'context' => $context,
            'status' => $response->status(),
        ]);

        throw new CompetitorAnalysisException($message, provider: 'apify', status: $response->status());
    }
}
