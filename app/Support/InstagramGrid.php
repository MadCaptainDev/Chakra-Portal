<?php

namespace App\Support;

use App\Models\SocialAccount;
use App\Models\SocialMediaItem;
use App\Services\Instagram\InstagramGraph;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * The client Instagram grids on the homepage's "Stills & static" tab: the
 * latest photo and carousel posts (no reels) of the accounts we run, as a
 * profile you can flip between.
 *
 * Instagram's image URLs are signed and expire within days, so the images
 * are copied into public/uploads/home-grid by `home:refresh-grid` (daily,
 * after the Instagram sync) and the homepage only ever reads the manifest
 * that command writes -- never Instagram, and never a URL that can die.
 * A post already copied is kept rather than downloaded again; one that
 * drops out of the grid has its file removed.
 */
class InstagramGrid
{
    /** Shown in this order. */
    public const ACCOUNTS = ['riyamakeover_artistry', 'zirabridalstudio', 'thillaipetsclinic_', 'mahavir.groups'];

    public const PER_ACCOUNT = 9;

    private const FOLDER = 'home-grid';

    public static function manifestPath(): string
    {
        return storage_path('app/home-grid.json');
    }

    /** @return list<array<string, mixed>> */
    public static function read(): array
    {
        try {
            $data = json_decode((string) @file_get_contents(self::manifestPath()), true);
        } catch (Throwable) {
            return [];
        }

        // Only accounts whose files are actually there.
        return collect(is_array($data) ? $data : [])
            ->map(function (array $account) {
                $account['posts'] = collect($account['posts'] ?? [])
                    ->filter(fn (array $post) => is_file(public_path($post['image'])))
                    ->values()
                    ->all();
                $account['avatar'] = ($account['avatar'] ?? null) && is_file(public_path($account['avatar'])) ? $account['avatar'] : null;

                return $account;
            })
            ->filter(fn (array $account) => count($account['posts']) >= 3)
            ->values()
            ->all();
    }

    /**
     * Rebuild the manifest. Returns one line per account for the console.
     *
     * @return list<string>
     */
    public static function refresh(?InstagramGraph $graph = null): array
    {
        $graph ??= app(InstagramGraph::class);
        $previous = collect(json_decode((string) @file_get_contents(self::manifestPath()), true) ?: []);
        $kept = [];
        $manifest = [];
        $report = [];

        foreach (self::ACCOUNTS as $username) {
            $account = SocialAccount::query()->connected()->where('username', $username)->first();

            if (! $account) {
                $report[] = "@{$username}: not connected, skipped";

                continue;
            }

            $old = $previous->firstWhere('username', $username) ?? [];
            $oldPosts = collect($old['posts'] ?? [])->keyBy('id');
            $since = StudioNumbers::workStartedFor($username);

            $items = SocialMediaItem::query()
                ->where('social_account_id', $account->id)
                ->when($since, fn ($q) => $q->where('posted_at', '>=', $since))
                // Stills only: photos and carousels, never reels.
                ->where('media_product_type', SocialMediaItem::PRODUCT_FEED)
                ->newestFirst()
                // Spares: a post deleted on Instagram can no longer be fetched.
                ->limit(self::PER_ACCOUNT * 2)
                ->get();

            $posts = [];

            foreach ($items as $item) {
                if (count($posts) >= self::PER_ACCOUNT) {
                    break;
                }

                $image = $oldPosts->get($item->id)['image'] ?? null;

                if (! $image || ! is_file(public_path($image))) {
                    $image = self::download($item, $account, $graph);
                }

                if (! $image) {
                    continue;
                }

                $kept[] = $image;
                $posts[] = [
                    'id' => $item->id,
                    'image' => $image,
                    'type' => $item->media_type === SocialMediaItem::TYPE_CAROUSEL ? 'carousel' : 'photo',
                    'url' => $item->permalink,
                ];
            }

            $avatar = $old['avatar'] ?? null;
            if (! $avatar || ! is_file(public_path($avatar))) {
                $avatar = self::avatar($account, $graph);
            }
            if ($avatar) {
                $kept[] = $avatar;
            }

            $manifest[] = [
                'username' => $username,
                'name' => $account->client?->publicName(),
                'avatar' => $avatar,
                'posts' => $posts,
            ];
            $report[] = "@{$username}: ".count($posts).' posts';
        }

        File::ensureDirectoryExists(dirname(self::manifestPath()));
        file_put_contents(self::manifestPath(), json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Files from the last run that nothing points at any more.
        foreach (File::glob(public_path('uploads/'.self::FOLDER.'/*')) as $file) {
            $relative = 'uploads/'.self::FOLDER.'/'.basename($file);
            if (! in_array($relative, $kept, true)) {
                PublicUpload::delete($relative);
            }
        }

        return $report;
    }

    /**
     * The cached URL first; when that has expired, a fresh one from the
     * Graph API (the same move the portfolio's thumbnail refresh makes).
     */
    private static function download(SocialMediaItem $item, SocialAccount $account, InstagramGraph $graph): ?string
    {
        $source = fn () => $item->thumbnail_url ?: $item->media_url;

        if ($source() && ($stored = PublicUpload::storeFromUrl($source(), self::FOLDER))) {
            return $stored;
        }

        try {
            $fresh = $graph->get($item->platform_media_id, $account->access_token, ['fields' => 'thumbnail_url,media_url']);
            $item->update([
                'thumbnail_url' => $fresh['thumbnail_url'] ?? $item->thumbnail_url,
                'media_url' => $fresh['media_url'] ?? $item->media_url,
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return $source() ? PublicUpload::storeFromUrl($source(), self::FOLDER) : null;
    }

    private static function avatar(SocialAccount $account, InstagramGraph $graph): ?string
    {
        if ($account->profile_picture_url && ($stored = PublicUpload::storeFromUrl($account->profile_picture_url, self::FOLDER))) {
            return $stored;
        }

        try {
            $fresh = $graph->get('me', $account->access_token, ['fields' => 'profile_picture_url']);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if (empty($fresh['profile_picture_url'])) {
            return null;
        }

        $account->forceFill(['profile_picture_url' => $fresh['profile_picture_url']])->save();

        return PublicUpload::storeFromUrl($fresh['profile_picture_url'], self::FOLDER);
    }
}
