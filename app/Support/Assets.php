<?php

namespace App\Support;

class Assets
{
    /**
     * Base64 data URI for a static image bundled under public/, so it
     * renders identically in the browser preview and the dompdf PDF
     * without relying on a reachable URL or filesystem path in either
     * context.
     */
    public static function image(string $relativePath): string
    {
        $path = public_path($relativePath);

        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            default => 'image/png',
        };

        return "data:{$mime};base64,".base64_encode(file_get_contents($path));
    }
}
