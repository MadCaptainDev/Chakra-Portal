<?php

namespace App\Support;

class Fonts
{
    /**
     * Base64 data URI for a font file in public/fonts, so it renders
     * identically in the browser preview and the dompdf PDF without
     * relying on a reachable URL or network font fetch.
     */
    public static function dataUri(string $filename): string
    {
        $path = public_path("fonts/{$filename}");

        return 'data:font/ttf;base64,'.base64_encode(file_get_contents($path));
    }
}
