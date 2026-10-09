<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\SocialInsight;
use App\Models\SocialMediaItem;
use App\Models\User;
use App\Notifications\AnnouncementPosted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * "This reel just crossed 50K views" -- an announcement each time a recent
 * reel or video passes 10K, 25K, 50K or 100K, so the team sees a win the
 * morning after it happens.
 *
 * Runs after every Instagram sync (SyncInstagramInsights) and on demand
 * (instagram:view-milestones). Each reel announces each step once: the
 * highest step announced is kept on the media row, so a reel that jumps
 * from 8K to 60K overnight gets one "crossed 50K", never a stale "10K" and
 * "25K" after it. Milestone announcements run for a week and then drop off
 * the dashboards on their own.
 */
class ViewMilestones
{
    public const STEPS = [10_000, 25_000, 50_000, 100_000];

    /** Only news while it is fresh: older posts are not checked. */
    public const RECENT_DAYS = 30;

    public const SHOW_FOR_DAYS = 7;

    /** @return int how many announcements were posted */
    public function run(bool $push = true): int
    {
        $views = SocialInsight::query()
            ->where('metric', 'views')
            ->whereNotNull('social_media_item_id')
            ->groupBy('social_media_item_id')
            ->selectRaw('social_media_item_id, MAX(value) as views')
            ->pluck('views', 'social_media_item_id');

        $posted = 0;

        SocialMediaItem::query()
            ->with('socialAccount.client')
            ->where('posted_at', '>=', now()->subDays(self::RECENT_DAYS))
            ->whereIn('media_type', [SocialMediaItem::TYPE_VIDEO])
            ->whereIn('id', $views->keys())
            ->get()
            ->each(function (SocialMediaItem $item) use ($views, $push, &$posted) {
                $count = (int) $views[$item->id];
                $step = self::stepFor($count);
                if ($step === null || $step <= (int) $item->views_milestone) {
                    return;
                }

                $announcement = DB::transaction(function () use ($item, $step, $count) {
                    // Claim the step first: two syncs racing must not both announce.
                    $claimed = SocialMediaItem::query()->whereKey($item->id)
                        ->where(fn ($q) => $q->whereNull('views_milestone')->orWhere('views_milestone', '<', $step))
                        ->update(['views_milestone' => $step]);

                    return $claimed ? Announcement::create($this->announcementFor($item, $step, $count)) : null;
                });

                if ($announcement) {
                    $posted++;
                    if ($push) {
                        $this->push($announcement);
                    }
                }
            });

        return $posted;
    }

    /** The highest step this many views has passed, or null below the first. */
    public static function stepFor(int $views): ?int
    {
        $passed = array_filter(self::STEPS, fn (int $step) => $views >= $step);

        return $passed === [] ? null : max($passed);
    }

    /** 10000 → "10K", 100000 → "100K", 1500000 → "1.5M". */
    public static function short(int $n): string
    {
        return match (true) {
            $n >= 1_000_000 => rtrim(rtrim(number_format($n / 1_000_000, 1), '0'), '.').'M',
            $n >= 1_000 => rtrim(rtrim(number_format($n / 1_000, 1), '0'), '.').'K',
            default => (string) $n,
        };
    }

    /** @return array<string, mixed> */
    private function announcementFor(SocialMediaItem $item, int $step, int $count): array
    {
        $account = $item->socialAccount;
        $who = $account?->client?->name ?? $account?->handle() ?? 'A';
        $what = $item->isReel() ? 'reel' : 'video';

        $lines = array_filter([
            $item->caption ? '“'.Str::limit(preg_replace('/\s+/', ' ', trim($item->caption)), 90).'”' : null,
            'Now at '.number_format($count).' views'.($item->posted_at ? ' — posted '.$item->posted_at->format('j M') : '').'.',
            $account?->handle(),
        ]);

        return [
            'title' => "🎉 {$who} {$what} crossed ".self::short($step).' views',
            'body' => implode("\n", $lines),
            'kind' => Announcement::KIND_MILESTONE,
            'link_url' => $item->permalink,
            'is_active' => true,
            'visible_to_clients' => false,
            'expires_at' => now()->addDays(self::SHOW_FOR_DAYS),
            'created_by' => null,
        ];
    }

    private function push(Announcement $announcement): void
    {
        try {
            Notification::send(User::staff()->get(), new AnnouncementPosted($announcement));
        } catch (Throwable $e) {
            Log::error('Views milestone push failed.', ['announcement_id' => $announcement->id, 'error' => $e->getMessage()]);
        }
    }
}
