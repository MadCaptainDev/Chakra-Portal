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

        // Every video from the last 30 days (the average's own sample) plus
        // the newest reels -- the brain wants all their scripts, not a top 12.
        $videos = collect($recentPosts)->concat($reels)
            ->filter(fn ($i) => filled($i['videoUrl'] ?? null))
            ->unique(fn ($i) => $this->postId($i))
            ->values()->all();

        foreach ($videos as $item) {
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
                    'short_code' => $item['shortCode'] ?? null,
                    'duration_seconds' => isset($item['videoDuration']) ? round((float) $item['videoDuration'], 1) : null,
                    'hashtags' => array_values((array) ($item['hashtags'] ?? [])) ?: null,
                    'music' => isset($item['musicInfo']) ? array_filter([
                        'song' => $item['musicInfo']['song_name'] ?? null,
                        'artist' => $item['musicInfo']['artist_name'] ?? null,
                        'original' => $item['musicInfo']['uses_original_audio'] ?? null,
                    ], fn ($v) => $v !== null) : null,
                    'comments' => collect((array) ($item['latestComments'] ?? []))
                        ->map(fn ($c) => ['text' => \Illuminate\Support\Str::limit((string) ($c['text'] ?? ''), 300), 'likes' => (int) ($c['likesCount'] ?? 0)])
                        ->filter(fn ($c) => $c['text'] !== '')->take(30)->values()->all() ?: null,
                ]
            );

            if ($reel->wasRecentlyCreated) {
                $newReels++;
            }
        }

        return ['reels' => count($videos), 'newReels' => $newReels];
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

    /** Instagram hides some counts; Apify sends those as -1. Unknown, not negative. */
    private function toInt(mixed $value): ?int
    {
        return $value === null || (int) $value < 0 ? null : (int) $value;
    }
}
