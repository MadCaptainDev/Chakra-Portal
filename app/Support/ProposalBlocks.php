<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The shapes a proposal is built from.
 *
 * A proposal's `sections` column is an ordered list of
 * {key, type, data}. Two section types exist:
 *
 * - `cover`: the dark title sheet (eyebrow, client logo, title, subtitle, the
 *   prepared-by / client / date row).
 * - `section`: one numbered heading ("01 Executive Summary") and a list of
 *   blocks. `new_page` starts a fresh A4 sheet before it -- that is how the
 *   Print Bazzar design's 22 pages are reproduced without storing pages.
 *
 * Block types are the patterns the Print Bazzar design actually uses, so the
 * document renders the way the design does rather than as generic markdown.
 *
 * The editor (resources/js/proposal-editor.js) works on this same stored
 * shape and posts it back whole; normalizeSections() is run on every save and
 * every read, so it is the one gate on what is kept -- and a view never
 * guards against a missing key.
 */
class ProposalBlocks
{
    public const SECTION_TYPES = [
        'cover' => 'Cover',
        'section' => 'Numbered section',
    ];

    public const BLOCK_TYPES = [
        'paragraph' => 'Paragraph',
        'subheading' => 'Subheading',
        'list' => 'List',
        'table' => 'Table',
        'callout' => 'Callout box',
        'cards' => 'Cards grid',
        'flow' => 'Flow of steps',
        'chips' => 'Pills',
        'note' => 'Tagged note',
        'legend' => 'How-to-read legend',
        'total' => 'Total bar',
        'stack' => 'Technology stack',
        'architecture' => 'Architecture layers',
        'swimlane' => 'Swimlane flow map',
    ];

    /** The three tags the design uses to mark who a statement belongs to. */
    public const TAGS = [
        'requirement' => 'Client Requirement',
        'recommendation' => 'Proposed Recommendation',
        'optional' => 'Optional / Future',
    ];

    /** Logos bundled under public/images/proposals/tech/{key}.svg. */
    public const TECH_LOGOS = ['livewire', 'laravel', 'mysql', 'razorpay', 'flutter'];

    /* ------------------------------------------------------------------ */
    /*  Normalising stored JSON */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<int, mixed>  $sections
     * @return list<array{key: string, type: string, data: array<string, mixed>}>
     */
    public static function normalizeSections(array $sections): array
    {
        $out = [];
        $seen = [];

        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $type = array_key_exists($section['type'] ?? null, self::SECTION_TYPES) ? $section['type'] : 'section';
            $key = self::cleanKey($section['key'] ?? null);

            // A duplicated key would make two sections share one comment
            // thread; the second one gets a fresh key instead.
            if ($key === null || isset($seen[$key])) {
                $key = self::newKey();
            }
            $seen[$key] = true;

            $data = is_array($section['data'] ?? null) ? $section['data'] : [];

            $out[] = [
                'key' => $key,
                'type' => $type,
                'data' => $type === 'cover' ? self::normalizeCover($data) : self::normalizeSection($data),
            ];
        }

        return $out;
    }

    public static function newKey(): string
    {
        return 's-'.Str::lower(Str::random(8));
    }

    private static function cleanKey(mixed $key): ?string
    {
        if (! is_string($key)) {
            return null;
        }

        $key = Str::limit(preg_replace('/[^a-z0-9\-]/', '', Str::lower($key)), 60, '');

        return $key === '' ? null : $key;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeCover(array $data): array
    {
        return [
            'eyebrow' => self::str($data['eyebrow'] ?? 'Project Proposal'),
            'brand' => in_array($data['brand'] ?? null, ['app_studio', 'production'], true) ? $data['brand'] : 'app_studio',
            'prepared_for_label' => self::str($data['prepared_for_label'] ?? 'Prepared for'),
            'client_logo' => self::logoPath($data['client_logo'] ?? null),
            'title' => self::str($data['title'] ?? ''),
            'subtitle' => self::str($data['subtitle'] ?? ''),
            'prepared_by' => self::str($data['prepared_by'] ?? 'Chakra App Studio'),
            'client_name' => self::str($data['client_name'] ?? ''),
            'date_label' => self::str($data['date_label'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeSection(array $data): array
    {
        $blocks = [];
        foreach ((array) ($data['blocks'] ?? []) as $block) {
            if (is_array($block) && array_key_exists($block['type'] ?? null, self::BLOCK_TYPES)) {
                $blocks[] = self::normalizeBlock($block);
            }
        }

        return [
            'number' => self::str($data['number'] ?? '', 8),
            'title' => self::str($data['title'] ?? ''),
            'new_page' => (bool) ($data['new_page'] ?? false),
            'blocks' => $blocks,
        ];
    }

    /**
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    public static function normalizeBlock(array $b): array
    {
        $type = $b['type'];

        return ['type' => $type] + match ($type) {
            'paragraph' => [
                'text' => self::str($b['text'] ?? '', 5000),
                'tone' => self::oneOf($b['tone'] ?? null, ['default', 'muted', 'fine']),
            ],
            'subheading' => ['text' => self::str($b['text'] ?? '')],
            'list' => [
                'items' => self::strList($b['items'] ?? []),
                'ordered' => (bool) ($b['ordered'] ?? false),
                'boxed' => (bool) ($b['boxed'] ?? false),
            ],
            'table' => [
                'header' => self::strList($b['header'] ?? [], keepEmpty: true),
                'rows' => array_values(array_map(
                    fn ($row) => self::strList(is_array($row) ? $row : [], keepEmpty: true),
                    array_filter((array) ($b['rows'] ?? []), 'is_array')
                )),
                'first_col_bold' => (bool) ($b['first_col_bold'] ?? false),
                'last_col_right' => (bool) ($b['last_col_right'] ?? false),
                'last_row_bold' => (bool) ($b['last_row_bold'] ?? false),
                'first_col_width' => max(0, min(60, (int) ($b['first_col_width'] ?? 0))),
            ],
            'callout' => [
                'text' => self::str($b['text'] ?? '', 2000),
                'tone' => self::oneOf($b['tone'] ?? null, ['brand', 'gray', 'outline']),
            ],
            'cards' => [
                'items' => array_values(array_map(fn ($i) => [
                    'label' => self::str($i['label'] ?? ''),
                    'text' => self::str($i['text'] ?? '', 1000),
                ], array_filter((array) ($b['items'] ?? []), 'is_array'))),
                'columns' => max(1, min(4, (int) ($b['columns'] ?? 2))),
                'variant' => self::oneOf($b['variant'] ?? null, ['labelled', 'plain', 'stat']),
            ],
            'flow' => [
                'steps' => self::strList($b['steps'] ?? []),
                'tone' => self::oneOf($b['tone'] ?? null, ['light', 'dark', 'line']),
                'numbered' => (bool) ($b['numbered'] ?? false),
                'columns' => max(0, min(10, (int) ($b['columns'] ?? 0))),
            ],
            'chips' => ['items' => self::strList($b['items'] ?? [])],
            'note' => [
                'tag' => self::oneOf($b['tag'] ?? null, array_keys(self::TAGS), 'recommendation'),
                'text' => self::str($b['text'] ?? '', 2000),
            ],
            'legend' => [
                'title' => self::str($b['title'] ?? 'How to read this proposal'),
                'items' => array_values(array_map(fn ($i) => [
                    'tag' => self::oneOf($i['tag'] ?? null, array_keys(self::TAGS), 'requirement'),
                    'label' => self::str($i['label'] ?? ''),
                    'note' => self::str($i['note'] ?? ''),
                ], array_filter((array) ($b['items'] ?? []), 'is_array'))),
            ],
            'total' => [
                'label' => self::str($b['label'] ?? ''),
                'value' => self::str($b['value'] ?? ''),
            ],
            'stack' => [
                'items' => array_values(array_map(fn ($i) => [
                    'category' => self::str($i['category'] ?? ''),
                    'name' => self::str($i['name'] ?? ''),
                    'logo' => in_array($i['logo'] ?? null, self::TECH_LOGOS, true) ? $i['logo'] : '',
                ], array_filter((array) ($b['items'] ?? []), 'is_array'))),
            ],
            'architecture' => [
                'layers' => array_values(array_map(fn ($l) => [
                    'label' => self::str($l['label'] ?? ''),
                    'style' => self::oneOf($l['style'] ?? null, ['light', 'dark', 'outline']),
                    'columns' => max(1, min(6, (int) ($l['columns'] ?? 4))),
                    'items' => array_values(array_map(fn ($i) => [
                        'text' => self::str($i['text'] ?? ''),
                        'dashed' => (bool) ($i['dashed'] ?? false),
                    ], array_filter((array) ($l['items'] ?? []), 'is_array'))),
                ], array_filter((array) ($b['layers'] ?? []), 'is_array'))),
            ],
            'swimlane' => [
                'lanes' => array_pad(array_slice(self::strList($b['lanes'] ?? [], keepEmpty: true), 0, 3), 3, ''),
                'rows' => array_values(array_map(
                    fn ($row) => array_pad(array_slice(array_values(array_map(fn ($c) => [
                        'text' => self::str(is_array($c) ? ($c['text'] ?? '') : ''),
                        'loop' => is_array($c) && (bool) ($c['loop'] ?? false),
                    ], (array) $row)), 0, 3), 3, ['text' => '', 'loop' => false]),
                    array_filter((array) ($b['rows'] ?? []), 'is_array')
                )),
                'legend' => (bool) ($b['legend'] ?? true),
            ],
        };
    }

    /* ------------------------------------------------------------------ */
    /*  Rendering helpers */
    /* ------------------------------------------------------------------ */

    /**
     * Escape, then allow the three inline marks the design uses:
     * **bold**, [ok]green bold[/ok] and [warn]amber bold[/warn].
     * Escaped first, so nothing a staff member types becomes live markup.
     */
    public static function inline(?string $text): string
    {
        $html = e((string) $text);

        $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);
        $html = preg_replace('/\[ok\](.+?)\[\/ok\]/s', '<strong class="cp-ok">$1</strong>', $html);
        $html = preg_replace('/\[warn\](.+?)\[\/warn\]/s', '<strong class="cp-warn">$1</strong>', $html);

        return $html;
    }

    /**
     * A paragraph block's text split on blank lines, so one block can carry
     * several paragraphs.
     *
     * @return list<string>
     */
    public static function paragraphs(?string $text): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\R\s*\R/', (string) $text)),
            fn ($p) => $p !== ''
        ));
    }

    /**
     * Group sections into sheets: the cover is always its own sheet, and a
     * section with new_page starts a new one.
     *
     * @param  list<array{key: string, type: string, data: array<string, mixed>}>  $sections
     * @return list<array{cover: bool, sections: list<array<string, mixed>>}>
     */
    public static function sheets(array $sections): array
    {
        $sheets = [];

        foreach ($sections as $section) {
            if ($section['type'] === 'cover') {
                $sheets[] = ['cover' => true, 'sections' => [$section]];

                continue;
            }

            $last = array_key_last($sheets);
            if ($last === null || $sheets[$last]['cover'] || $section['data']['new_page']) {
                $sheets[] = ['cover' => false, 'sections' => []];
                $last = array_key_last($sheets);
            }

            $sheets[$last]['sections'][] = $section;
        }

        return $sheets;
    }

    public static function techLogoPath(string $key): ?string
    {
        $path = 'images/proposals/tech/'.$key.'.svg';

        return in_array($key, self::TECH_LOGOS, true) && is_file(public_path($path)) ? $path : null;
    }

    /* ------------------------------------------------------------------ */
    /*  Small normalisers */
    /* ------------------------------------------------------------------ */

    private static function str(mixed $value, int $max = 500): string
    {
        return is_scalar($value) ? Str::limit(trim((string) $value), $max, '') : '';
    }

    /** @return list<string> */
    private static function strList(mixed $items, bool $keepEmpty = false): array
    {
        $out = array_map(fn ($i) => self::str($i, 1000), array_values((array) $items));

        return $keepEmpty ? $out : array_values(array_filter($out, fn ($i) => $i !== ''));
    }

    /** @param list<string> $allowed */
    private static function oneOf(mixed $value, array $allowed, ?string $default = null): string
    {
        return in_array($value, $allowed, true) ? $value : ($default ?? $allowed[0]);
    }

    /**
     * A cover logo must be a file under public/ that this app put there --
     * bundled proposal art or an upload -- never an arbitrary path or URL.
     */
    private static function logoPath(mixed $path): ?string
    {
        if (! is_string($path) || $path === '' || str_contains($path, '..')) {
            return null;
        }

        return str_starts_with($path, 'images/proposals/') || str_starts_with($path, 'uploads/proposals/') ? $path : null;
    }
}
