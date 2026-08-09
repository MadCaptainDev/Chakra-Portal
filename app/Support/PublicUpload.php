<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
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
