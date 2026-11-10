<?php

namespace App\Services\Videos;

use App\Models\SocialMediaItem;
use App\Models\Video;
use App\Services\Instagram\InstagramGraph;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The smallest useful picture of a published video, kept on our own disk.
 *
 * Instagram gives one thumbnail per post, at full frame size, behind a link
 * that expires in a few days. This fetches it once, shrinks it to WIDTH
 * pixels wide as WebP -- a few kilobytes -- and saves it under
 * public/uploads/video-thumbs (see PublicUpload for why public/uploads).
 *
 * Never throws: a video without a picture is still a video.
 */
class VideoThumbnail
{
    /** Wide enough for a list row or a planner card at 2x, and no wider. */
    public const WIDTH = 160;

    public const QUALITY = 60;

    private const FOLDER = 'uploads/video-thumbs';

    /** @return string|null the saved path, relative to public/ */
    public static function store(Video $video): ?string
    {
        if ($video->thumbnail_path && is_file(public_path($video->thumbnail_path))) {
            return $video->thumbnail_path;
        }

        try {
            $media = self::mediaFor($video);
            $bytes = $media ? self::fetch($media) : null;
            $path = $bytes ? self::shrink($bytes, $video) : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if ($path === null) {
            return null;
        }

        $video->forceFill(['thumbnail_path' => $path, 'thumbnail_saved_at' => now()])->save();

        return $path;
    }

    /**
     * Published videos that have an Instagram post and no picture yet.
     * Called after each Instagram sync, when the day's links are fresh.
     */
    public static function backfill(int $limit = 60): int
    {
        $saved = 0;

        Video::query()
            ->whereNull('thumbnail_path')
            ->where(fn ($q) => $q->whereNotNull('social_media_item_id')
                ->orWhereHas('todo', fn ($t) => $t->whereNotNull('posted_at')->where('post_url', 'like', '%instagram.com%')))
            ->with(['mediaItem.socialAccount', 'todo:id,post_url,posted_at', 'contentItem:id,social_media_item_id'])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->each(function (Video $video) use (&$saved) {
                if (self::store($video)) {
                    $saved++;
                }
            });

        return $saved;
    }

    /**
     * The Instagram post this video went out as: the one already matched to
     * it, or -- for a video marked posted in My Editing with its link -- the
     * synced post with the same /reel/<code>/ in its permalink.
     */
    public static function mediaFor(Video $video): ?SocialMediaItem
    {
        if ($video->mediaItem) {
            return $video->mediaItem;
        }

        $id = $video->contentItem?->social_media_item_id;
        $media = $id ? SocialMediaItem::find($id) : null;

        if (! $media && ($code = self::shortcode($video->todo?->post_url))) {
            $media = SocialMediaItem::query()->where('permalink', 'like', '%/'.$code.'%')->first();
        }

        if ($media) {
            $video->forceFill(['social_media_item_id' => $media->id])->save();
            $video->setRelation('mediaItem', $media);
        }

        return $media;
    }

    /** "DLx3abcQ9z" from https://www.instagram.com/reel/DLx3abcQ9z/?igsh=… */
    public static function shortcode(?string $url): ?string
    {
        return $url && preg_match('#instagram\.com/(?:[^/]+/)?(?:reel|reels|p|tv)/([A-Za-z0-9_-]{5,})#', $url, $m) ? $m[1] : null;
    }

    /** The picture's bytes, asking Instagram for a fresh link if the stored one has expired. */
    private static function fetch(SocialMediaItem $media): ?string
    {
        $bytes = self::download(self::imageUrl($media));
        if ($bytes !== null) {
            return $bytes;
        }

        $token = $media->socialAccount?->access_token;
        if (! $token) {
            return null;
        }

        $fresh = app(InstagramGraph::class)->get($media->platform_media_id, $token, ['fields' => 'thumbnail_url,media_url,media_type']);
        $media->forceFill(array_filter([
            'thumbnail_url' => $fresh['thumbnail_url'] ?? null,
            'media_url' => $fresh['media_url'] ?? null,
        ]))->save();

        return self::download(self::imageUrl($media));
    }

    /** A video post's media_url is the video itself; only its thumbnail is a picture. */
    private static function imageUrl(SocialMediaItem $media): ?string
    {
        return $media->thumbnail_url ?: (strtoupper((string) $media->media_type) === 'VIDEO' ? null : $media->media_url);
    }

    private static function download(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $response = Http::timeout(15)->get($url);

        return $response->successful() && str_starts_with((string) $response->header('Content-Type'), 'image/')
            ? $response->body()
            : null;
    }

    private static function shrink(string $bytes, Video $video): ?string
    {
        if (! function_exists('imagewebp') || ! ($image = @imagecreatefromstring($bytes))) {
            return null;
        }

        [$width, $height] = [imagesx($image), imagesy($image)];
        $scale = min(1, self::WIDTH / $width);
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        // imagecopyresampled, as ImageVariant does: imagescale() fails on this host's GD.
        $small = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($small, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($image);

        $relative = self::FOLDER.'/'.$video->id.'-'.substr(sha1($bytes), 0, 10).'.webp';
        $target = public_path($relative);
        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }

        $written = imagewebp($small, $target, self::QUALITY);
        imagedestroy($small);

        return $written ? $relative : null;
    }
}
