<?php

namespace App\Support;

use Throwable;

/**
 * A smaller copy of an image that is already under public/, for pages that
 * show many at once.
 *
 * Client logos are uploaded at whatever size the client had -- one is a 2 MB
 * JPEG -- and portfolio covers are full Instagram frames. The showreel page
 * puts a dozen of each on screen at once, so it asks for a copy that fits a
 * box, as WebP. The copy is written once, next to the uploads (see
 * PublicUpload for why public/uploads and not a disk), and its name carries
 * the source's modification time: replacing a logo makes a new copy rather
 * than serving the old one.
 *
 * Never throws. Anything that cannot be resized -- a missing file, an
 * unsupported format, no GD -- falls back to the original's URL.
 */
class ImageVariant
{
    private const FOLDER = 'uploads/variants';

    public static function url(string $publicPath, int $maxWidth, int $maxHeight): string
    {
        $source = public_path($publicPath);

        if (! is_file($source)) {
            return asset($publicPath);
        }

        $name = sha1($publicPath.'|'.filemtime($source).'|'.$maxWidth.'x'.$maxHeight).'.webp';
        $relative = self::FOLDER.'/'.$name;
        $target = public_path($relative);

        if (is_file($target)) {
            return asset($relative);
        }

        try {
            return self::make($source, $target, $maxWidth, $maxHeight) ? asset($relative) : asset($publicPath);
        } catch (Throwable $e) {
            report($e);

            return asset($publicPath);
        }
    }

    private static function make(string $source, string $target, int $maxWidth, int $maxHeight): bool
    {
        if (! function_exists('imagewebp')) {
            return false;
        }

        $info = @getimagesize($source);
        if (! $info) {
            return false;
        }

        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => @imagecreatefromwebp($source),
            IMAGETYPE_GIF => @imagecreatefromgif($source),
            default => false,
        };

        if (! $image) {
            return false;
        }

        [$width, $height] = [imagesx($image), imagesy($image)];
        $scale = min(1, $maxWidth / $width, $maxHeight / $height);
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        // Transparent logos stay transparent.
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagefill($resized, 0, 0, imagecolorallocatealpha($resized, 0, 0, 0, 127));
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }

        // Written under a temporary name and moved into place, so a visitor
        // arriving mid-write never gets half a file.
        $temporary = $target.'.'.uniqid('', true).'.tmp';
        $written = imagewebp($resized, $temporary, 82);

        imagedestroy($image);
        imagedestroy($resized);

        if ($written && rename($temporary, $target)) {
            return true;
        }

        @unlink($temporary);

        return false;
    }
}
