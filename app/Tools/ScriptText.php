<?php

namespace App\Tools;

use App\Models\Script;

/**
 * Script text in and out of the MCP tools: what a model sends (one block of
 * text, or headed sections) to the editor's HTML, and the stored HTML back to
 * plain text a model can read and send back edited. Words are never changed
 * on the way through -- only blank lines become paragraphs and back.
 */
class ScriptText
{
    /**
     * The sections a call asked for, as [heading, text] pairs; null when the
     * call sent neither text nor sections and $required is false.
     *
     * @return list<array{0: string, 1: string}>|null
     */
    public static function fromArguments(array $arguments, bool $required = true): ?array
    {
        $text = trim((string) ($arguments['text'] ?? ''));
        $given = $arguments['sections'] ?? [];

        if ($text !== '' && ! empty($given)) {
            throw new ToolException('Send the script as text or as sections, not both.');
        }

        if ($text !== '') {
            return [['Script', $text]];
        }

        if (empty($given)) {
            if ($required) {
                throw new ToolException('There is no script text. Send it as text or as sections.');
            }

            return null;
        }

        $out = [];
        foreach ((array) $given as $part) {
            $heading = trim((string) ($part['heading'] ?? ''));
            $body = trim((string) ($part['text'] ?? ''));
            if ($heading === '' || $body === '') {
                throw new ToolException('Every section needs a heading and some text.');
            }
            $out[] = [mb_substr($heading, 0, 255), $body];
        }

        return $out;
    }

    /** Plain text to the editor's HTML: blank lines make paragraphs, single breaks stay. */
    public static function html(string $text): string
    {
        $paragraphs = preg_split('/\R\s*\R/u', str_replace("\r\n", "\n", trim($text)));

        return collect($paragraphs)
            ->map(fn ($p) => trim($p))
            ->filter()
            ->map(fn ($p) => '<p>'.nl2br(e($p), false).'</p>')
            ->implode('');
    }

    /** The stored HTML back to plain text: paragraphs as blank lines, breaks as newlines. */
    public static function plain(?string $html): string
    {
        $text = preg_replace('#<br\s*/?>\s*#i', "\n", (string) $html);
        $text = preg_replace('#</p>\s*#i', "\n\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /** "#226", or 226 -- the number people say a script by. */
    public static function find(mixed $value): Script
    {
        $id = ltrim(trim((string) $value), '#');

        if (! ctype_digit($id)) {
            throw new ToolException('Say which script by its number, e.g. #226. list_scripts shows them.');
        }

        return Script::with(['client', 'sections', 'writer'])->find((int) $id)
            ?? throw new ToolException("There is no script #{$id}.");
    }
}
