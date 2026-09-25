<?php

namespace App\Tools\Proposals;

use App\Models\Client;
use App\Models\Proposal;
use App\Support\ProposalBlocks;
use App\Tools\ToolException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * What the proposal tools share: finding a proposal or client from whatever
 * a model typed, describing a proposal back, and -- the part that matters --
 * taking sections a model wrote and turning them into stored ones without
 * losing anything silently.
 *
 * ProposalBlocks::normalizeSections() is forgiving by design: an unknown
 * block is dropped, a bad tone falls back to the default, a long field is
 * cut. That is right for the editor, which cannot produce those, and wrong
 * for a model, which can and would never find out. So everything the
 * normaliser is about to change is reported back as a problem the model
 * reads, and the things that would lose content outright are refused.
 */
class ProposalToolSupport
{
    /** A proposal by id, or by (part of) its title when that is unambiguous. */
    public static function find(mixed $ref): Proposal
    {
        if (is_int($ref) || (is_string($ref) && ctype_digit(trim($ref)))) {
            $proposal = Proposal::find((int) $ref);
            if ($proposal) {
                return $proposal;
            }

            throw new ToolException('There is no proposal #'.$ref.'. Call list_proposals to see them.');
        }

        $needle = trim((string) $ref);
        if ($needle === '') {
            throw new ToolException('Say which proposal: its id (from list_proposals) or its title.');
        }

        $exact = Proposal::where('title', $needle)->get();
        $matches = $exact->isNotEmpty() ? $exact : Proposal::where('title', 'like', '%'.$needle.'%')->get();

        if ($matches->count() === 1) {
            return $matches->first();
        }

        if ($matches->isEmpty()) {
            throw new ToolException('No proposal has "'.$needle.'" in its title. Call list_proposals to see them.');
        }

        throw new ToolException('"'.$needle.'" matches several proposals: '
            .$matches->map(fn ($p) => '#'.$p->id.' '.$p->title)->implode('; ').'. Use the id.');
    }

    /** A client by id or name; null for "no client". */
    public static function client(mixed $ref): ?Client
    {
        if ($ref === null || (is_string($ref) && trim($ref) === '')) {
            return null;
        }

        if (is_int($ref) || ctype_digit(trim((string) $ref))) {
            return Client::find((int) $ref)
                ?? throw new ToolException('There is no client #'.$ref.'.');
        }

        $needle = trim((string) $ref);
        $matches = Client::where('name', $needle)->get();
        if ($matches->isEmpty()) {
            $matches = Client::where('name', 'like', '%'.$needle.'%')->get();
        }

        if ($matches->count() === 1) {
            return $matches->first();
        }

        if ($matches->isEmpty()) {
            throw new ToolException('No client is called "'.$needle.'". A proposal for a prospect '
                .'who is not a client yet can leave the client out and put their name on the cover.');
        }

        throw new ToolException('"'.$needle.'" matches several clients: '
            .$matches->map(fn ($c) => '#'.$c->id.' '.$c->name)->implode('; ').'. Use the id.');
    }

    public static function date(mixed $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (Throwable) {
            throw new ToolException('"'.$value.'" could not be read as a date. Use YYYY-MM-DD.');
        }
    }

    /**
     * The addresses a person needs: where to look at it and edit it in the
     * portal, the PDF, and the client's link if one exists.
     *
     * @return array<string, string|null>
     */
    public static function urls(Proposal $proposal): array
    {
        return [
            'view_url' => route('proposals.show', $proposal),
            'edit_url' => route('proposals.edit', $proposal),
            'pdf_url' => route('proposals.pdf', $proposal),
            'client_link' => $proposal->publicUrl(),
        ];
    }

    /** @return array<string, mixed> */
    public static function summary(Proposal $proposal): array
    {
        $proposal->loadMissing('client');

        return [
            'id' => $proposal->id,
            'title' => $proposal->title,
            'status' => $proposal->status,
            'client' => $proposal->client ? ['id' => $proposal->client->id, 'name' => $proposal->client->name] : null,
            'valid_until' => $proposal->valid_until?->toDateString(),
            'updated_at' => $proposal->updated_at?->toDateTimeString(),
            'first_viewed_by_client_at' => $proposal->first_viewed_at?->toDateTimeString(),
            'open_client_comments' => $proposal->unresolvedCommentCount(),
        ] + self::urls($proposal);
    }

    /**
     * The sections in reading order, as one line each -- the table of
     * contents a model works from before it asks for the full text.
     *
     * @return list<array<string, mixed>>
     */
    public static function outline(Proposal $proposal): array
    {
        $page = 0;

        return array_map(function (array $section) use (&$page) {
            if ($section['type'] === 'cover' || $page === 0 || $section['data']['new_page']) {
                $page++;
            }

            return [
                'key' => $section['key'],
                'type' => $section['type'],
                'page' => $page,
                'heading' => $section['type'] === 'cover'
                    ? 'Cover: '.($section['data']['title'] ?: '(untitled)')
                    : (trim($section['data']['number'].' '.$section['data']['title']) ?: '(no heading)'),
                'blocks' => $section['type'] === 'cover'
                    ? []
                    : array_map(fn ($b) => $b['type'], $section['data']['blocks']),
            ];
        }, $proposal->normalizedSections());
    }

    /* ------------------------------------------------------------------ */
    /*  Sections in                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Sections a model wrote, turned into stored ones.
     *
     * Accepts the stored shape {key, type, data: {...}} and the flatter one
     * {key, type, number, title, new_page, blocks} -- a model is told about
     * one and will use the other. The cover's client logo is kept from the
     * existing cover when the input leaves it out, since a logo can only be
     * uploaded in the editor and rewriting a cover must not lose it.
     *
     * @param  array<int, mixed>  $input
     * @param  array<string, array<string, mixed>>  $existingByKey  stored sections by key
     * @return array{sections: list<array<string, mixed>>, problems: list<string>}
     */
    public static function prepare(array $input, array $existingByKey = []): array
    {
        $raw = [];
        $problems = [];

        foreach (array_values($input) as $index => $section) {
            if (! is_array($section)) {
                throw new ToolException('Section '.($index + 1).' is not an object.');
            }

            $type = $section['type'] ?? 'section';
            if (! array_key_exists($type, ProposalBlocks::SECTION_TYPES)) {
                throw new ToolException('Section '.($index + 1).' has type "'.$type.'"; the types are "cover" and "section".');
            }

            $data = is_array($section['data'] ?? null)
                ? $section['data']
                : array_diff_key($section, array_flip(['key', 'type', 'data']));

            $key = $section['key'] ?? null;
            $label = is_string($key) && $key !== '' ? '"'.$key.'"' : 'section '.($index + 1);

            if ($type === 'cover') {
                if (! array_key_exists('client_logo', $data)) {
                    $data['client_logo'] = $existingByKey[$key]['data']['client_logo'] ?? self::existingCoverLogo($existingByKey);
                }
                $problems = [...$problems, ...self::fieldProblems($label, $data, ProposalBlocks::normalizeCover($data), ['client_logo'])];
            } else {
                $blocks = $data['blocks'] ?? [];
                if (! is_array($blocks)) {
                    throw new ToolException($label.': blocks must be a list.');
                }
                foreach (array_values($blocks) as $b => $block) {
                    $problems = [...$problems, ...self::blockProblems($label.' block '.($b + 1), $block)];
                }
                $problems = [...$problems, ...self::fieldProblems($label, array_diff_key($data, ['blocks' => 1]),
                    array_diff_key(ProposalBlocks::normalizeSection(array_diff_key($data, ['blocks' => 1])), ['blocks' => 1]))];
            }

            $raw[] = ['key' => is_string($key) ? $key : null, 'type' => $type, 'data' => $data];
        }

        return ['sections' => ProposalBlocks::normalizeSections($raw), 'problems' => $problems];
    }

    private static function existingCoverLogo(array $existingByKey): ?string
    {
        foreach ($existingByKey as $section) {
            if ($section['type'] === 'cover') {
                return $section['data']['client_logo'];
            }
        }

        return null;
    }

    /**
     * Refuses an unknown block type (its content would vanish) and reports
     * everything else the normaliser is about to change.
     *
     * @return list<string>
     */
    private static function blockProblems(string $label, mixed $block): array
    {
        if (! is_array($block)) {
            throw new ToolException($label.' is not an object.');
        }

        $type = $block['type'] ?? null;
        if (! is_string($type) || ! array_key_exists($type, ProposalBlocks::BLOCK_TYPES)) {
            throw new ToolException($label.' has type "'.(is_string($type) ? $type : '?').'". Block types are: '
                .implode(', ', array_keys(ProposalBlocks::BLOCK_TYPES)).'. Call proposal_guide for their shapes.');
        }

        return self::fieldProblems($label.' ('.$type.')', $block, ProposalBlocks::normalizeBlock($block));
    }

    /**
     * Walk what was sent against what will be stored: a field that is not
     * kept, a value replaced by a default, a string cut short.
     *
     * @param  array<string, mixed>  $sent
     * @param  array<string, mixed>  $kept
     * @param  list<string>  $skip
     * @return list<string>
     */
    private static function fieldProblems(string $label, array $sent, array $kept, array $skip = []): array
    {
        $problems = [];

        foreach ($sent as $field => $value) {
            if (in_array($field, $skip, true) || $field === '_uid') {
                continue;
            }

            if (! array_key_exists($field, $kept)) {
                $problems[] = $label.': "'.$field.'" is not a field of this block and was ignored.';

                continue;
            }

            $problems = [...$problems, ...self::valueProblems($label.' '.$field, $value, $kept[$field])];
        }

        return $problems;
    }

    /** @return list<string> */
    private static function valueProblems(string $path, mixed $sent, mixed $kept): array
    {
        if (is_string($sent)) {
            $trimmed = trim($sent);
            if ($trimmed === '' || $kept === $trimmed) {
                return [];
            }
            if (is_int($kept) || is_bool($kept)) {
                return $kept == $trimmed ? [] : [$path.' was adjusted to '.var_export($kept, true).'.'];
            }
            if (is_string($kept) && $kept !== '' && str_starts_with($trimmed, $kept)) {
                return [$path.' was too long and was cut to '.Str::length($kept).' characters. Split it up.'];
            }

            return [$path.': "'.Str::limit($trimmed, 40).'" is not an allowed value, so "'.(is_scalar($kept) ? $kept : '?').'" was used.'];
        }

        if (is_array($sent) && is_array($kept)) {
            $sentList = array_is_list($sent);
            if ($sentList && count($sent) !== count($kept)) {
                $dropped = count($sent) - count($kept);

                return $dropped > 0 ? [$path.': '.$dropped.' empty or malformed entr'.($dropped === 1 ? 'y was' : 'ies were').' dropped.'] : [];
            }

            $problems = [];
            foreach ($sent as $k => $v) {
                if (! array_key_exists($k, $kept)) {
                    if (! $sentList) {
                        $problems[] = $path.': "'.$k.'" was ignored.';
                    }

                    continue;
                }
                $problems = [...$problems, ...self::valueProblems($path.'['.$k.']', $v, $kept[$k])];
            }

            return $problems;
        }

        if ((is_int($sent) || is_float($sent)) && ! is_array($kept)) {
            return $kept == $sent ? [] : [$path.' was adjusted to '.var_export($kept, true).' (outside the allowed range).'];
        }

        if (is_array($sent) !== is_array($kept)) {
            return [$path.' has the wrong shape (expected '.(is_array($kept) ? 'a list or object' : 'a single value').'). Call proposal_guide.'];
        }

        return [];
    }
}
