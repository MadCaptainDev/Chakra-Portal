<?php

namespace App\Services\Competitors;

use App\Models\CompetitorAccount;
use App\Models\CompetitorReel;
use Illuminate\Support\Carbon;

/**
 * What a scrape actually means: three ApifyClient calls turned into stored
 * rows. ApifyClient itself is deliberately just plumbing (raw Apify dataset
 * items in, nothing normalized) -- this is where those items become
 * CompetitorReel rows and an updated CompetitorAccount average, the same
 * split InstagramSyncRunner keeps from InstagramGraph.
 *
 * Fast enough to call from a web request (three bounded Apify runs, not
 * Gemini's own upload-and-poll), matching InstagramSyncRunner's own
 * precedent for what belongs in-request versus CLI-only.
 */
class CompetitorScraper
{
    public function __construct(private readonly ApifyClient $apify) {}

    public static function make(): self
    {
        return new self(ApifyClient::make());
    }

    /**
     * @return array{reels: int, newReels: int}
     */
    public function scrape(CompetitorAccount $account, int $days = 30, int $maxVideos = 12): array
    {
        $profile = $this->apify->scrapeProfile($account->username);
        $recentPosts = $this->apify->scrapeRecentPosts($account->username);
        $reels = $this->apify->scrapeReels($account->username, $maxVideos, $days);

        $account->forceFill([
            'profile_pic_url' => $profile['profilePicUrl'] ?? $account->profile_pic_url,
            'followers_count' => $profile['followersCount'] ?? $account->followers_count,
            'avg_views_30d' => $this->averageViews($recentPosts),
            'last_scraped_at' => now(),
        ])->save();

        $newReels = 0;

        foreach ($reels as $item) {
            $postId = $this->postId($item);

            if ($postId === null || blank($item['videoUrl'] ?? null)) {
                continue;
            }

            $reel = $account->reels()->updateOrCreate(
                ['platform_post_id' => $postId],
                [
                    'video_url' => $item['videoUrl'],
                    'thumbnail_url' => $item['displayUrl'] ?? null,
                    'caption' => $item['caption'] ?? null,
                    'play_count' => $this->toInt($item['videoPlayCount'] ?? null),
                    'like_count' => $this->toInt($item['likesCount'] ?? null),
                    'comment_count' => $this->toInt($item['commentsCount'] ?? null),
                    'posted_at' => isset($item['timestamp']) ? Carbon::parse($item['timestamp']) : null,
                    'scraped_at' => now(),
                ]
            );

            if ($reel->wasRecentlyCreated) {
                $newReels++;
            }
        }

        return ['reels' => count($reels), 'newReels' => $newReels];
    }

    /**
     * @param  list<array<string, mixed>>  $posts
     */
    private function averageViews(array $posts): ?int
    {
        $views = collect($posts)
            ->pluck('videoPlayCount')
            ->filter(fn ($count) => $count !== null)
            ->map(fn ($count) => (int) $count);

        return $views->isNotEmpty() ? (int) round($views->avg()) : null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function postId(array $item): ?string
    {
        $id = $item['id'] ?? $item['shortCode'] ?? $item['url'] ?? null;

        return $id !== null ? (string) $id : null;
    }

    private function toInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
