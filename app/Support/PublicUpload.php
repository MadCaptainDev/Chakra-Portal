<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Files that have to be reachable by a browser -- portfolio videos, thumbnails,
 * team photos.
 *
 * They are written straight into public/uploads rather than through the
 * "public" disk: that disk lives in storage/ and is only reachable via the
 * public/storage symlink, and the production host has symlink() disabled, so
 * `storage:link` can never be run there. Everything here is therefore stored as
 * a path relative to public/ ("uploads/portfolio/x.mp4") that asset() resolves.
 */
class PublicUpload
{
    /**
     * Move an upload under public/uploads/{folder} and return its public path.
     */
    public static function store(UploadedFile $file, string $folder): string
    {
        $folder = trim($folder, '/');

        // The original name is kept only as a readable hint; the random prefix
        // is what makes the name unique and unguessable.
        $stem = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'file';
        $extension = Str::lower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');
        $name = Str::random(16).'-'.Str::limit($stem, 60, '').'.'.$extension;

        $file->move(public_path('uploads/'.$folder), $name);

        return 'uploads/'.$folder.'/'.$name;
    }

    /**
     * Download a remote file (an Instagram CDN thumbnail) and store it exactly
     * like a local upload, under public/uploads/{folder}.
     *
     * Never throws. A dead or expired signed URL -- which is the normal
     * failure mode for Instagram's thumbnail_url/media_url, see
     * SocialMediaItem -- must degrade to "no thumbnail", not break a
     * portfolio save. Returns null on any failure: an unreachable host, a
     * non-2xx response, a content type that isn't a recognised image, an
     * empty body, or a body over the size ceiling.
     */
    public static function storeFromUrl(string $url, string $folder, int $maxKb = 8192): ?string
    {
        try {
            $response = Http::timeout(10)->get($url);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        // Content-type allowlist, not extension-sniffing off the URL --
        // Instagram's CDN URLs carry a signed token as their path, not a
        // reliable file extension.
        $extension = match (true) {
            str_contains((string) $response->header('Content-Type'), 'jpeg') => 'jpg',
            str_contains((string) $response->header('Content-Type'), 'png') => 'png',
            str_contains((string) $response->header('Content-Type'), 'webp') => 'webp',
            default => null,
        };

        $body = $response->body();

        if ($extension === null || $body === '' || strlen($body) > $maxKb * 1024) {
            return null;
        }

        $folder = trim($folder, '/');
        $name = Str::random(16).'-instagram.'.$extension;
        $dir = public_path('uploads/'.$folder);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($dir.'/'.$name, $body);

        return 'uploads/'.$folder.'/'.$name;
    }

    /**
     * Delete a file this class wrote. Anything outside public/uploads is left
     * alone, so a hand-edited path can never remove a bundled asset.
     */
    public static function delete(?string $path): void
    {
        if (! $path || ! str_starts_with($path, 'uploads/') || str_contains($path, '..')) {
            return;
        }

        $full = public_path($path);

        if (is_file($full)) {
            @unlink($full);
        }
    }
}
