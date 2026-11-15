<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * AI-written Markdown (Gemini's reel breakdowns and concepts) as safe HTML:
 * raw HTML in the text is stripped, links can't run script, and the LaTeX
 * arrows Gemini likes ($\rightarrow$) become plain arrows.
 */
class AiMarkdown
{
    public static function html(?string $text): string
    {
        $text = strtr((string) $text, [
            '$\rightarrow$' => '→', '$\to$' => '→', '$\leftarrow$' => '←', '$\times$' => '×',
        ]);

        return Str::markdown($text, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }
}
