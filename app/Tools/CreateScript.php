<?php

namespace App\Tools;

use App\Models\Script;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class CreateScript extends Tool
{
    public function name(): string
    {
        return 'create_script';
    }

    public function title(): string
    {
        return 'Add a script';
    }

    public function group(): string
    {
        return 'Scripts';
    }

    public function description(): string
    {
        return 'Add a written reel script to a client\'s Scripts, as a Draft the team then edits, '
            .'reviews and sends from the portal. Pass the text exactly as written -- do not '
            .'translate, tidy or rewrite Tanglish. Give it as one "text", or as "sections" '
            .'(Hook, Body, CTA...) when the script already has clear parts. One script per call; '
            .'a title the client already has is refused so a retry cannot make a copy. This '
            .'never sends anything to the client.';
    }

    public function permission(): ?string
    {
        return 'scripts.create';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    /** Whole scripts are long; the WhatsApp assistant has no use for this. */
    public function mcpOnly(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([
            'client' => ['type' => 'string', 'description' => 'Which client it is for: their id, or their name as it is in the portal.'],
            'title' => ['type' => 'string', 'description' => 'A short name for the video, e.g. "3 Kitchen Colour Combinations".'],
            'text' => ['type' => 'string', 'description' => 'The whole script as written. Blank lines separate paragraphs. Use this or sections.'],
            'sections' => [
                'type' => 'array',
                'description' => 'The script in named parts, in order. Use this or text.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'heading' => ['type' => 'string', 'description' => 'e.g. Hook, Body, CTA, Voice Over.'],
                        'text' => ['type' => 'string', 'description' => 'What is said or shown in this part.'],
                    ],
                    'required' => ['heading', 'text'],
                ],
            ],
            'writer' => ['type' => 'string', 'description' => 'Who wrote it, by name or email. Defaults to the caller.'],
            'priority' => ['type' => 'string', 'enum' => array_keys(Script::PRIORITIES), 'description' => 'Defaults to normal.'],
            'due_on' => ['type' => 'string', 'format' => 'date', 'description' => 'When it is needed by, YYYY-MM-DD.'],
            'target_seconds' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 36000, 'description' => 'How long the reel should run, in seconds.'],
        ], ['client', 'title']);
    }

    public function handle(array $arguments, User $user): array
    {
        $title = trim((string) ($arguments['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 255) {
            throw new ToolException('Give the script a title of up to 255 characters.');
        }

        $sections = ScriptText::fromArguments($arguments);
        $client = ClientResolver::resolve($arguments['client'] ?? null);
        $writer = People::assignable($arguments['writer'] ?? null, $user);

        $existing = Script::where('client_id', $client->id)
            ->whereRaw('LOWER(TRIM(title)) = ?', [mb_strtolower($title)])
            ->first();
        if ($existing) {
            throw new ToolException("{$client->name} already has a script called \"{$existing->title}\" (id {$existing->id}). "
                .'Use a different title if this is a different video.');
        }

        $script = DB::transaction(function () use ($arguments, $title, $sections, $client, $writer, $user) {
            $script = new Script([
                'client_id' => $client->id,
                'title' => $title,
                'status' => Script::STATUS_DRAFT,
                'priority' => $arguments['priority'] ?? Script::PRIORITY_NORMAL,
                'writer_id' => $writer->id,
                'target_seconds' => $arguments['target_seconds'] ?? null,
                'due_on' => $this->date($arguments['due_on'] ?? null)?->toDateString(),
            ]);
            $script->created_by_id = $user->id;
            $script->save();

            // Bodies go through ScriptSection's sanitising mutator like every
            // other write; position is set here as the reorder action would.
            foreach ($sections as $i => [$heading, $text]) {
                $section = $script->sections()->make(['heading' => $heading]);
                $section->position = $i;
                $section->body = ScriptText::html($text);
                $section->save();
            }

            $script->touchEditedBy($user);

            return $script;
        });

        return [
            'id' => $script->id,
            'number' => '#'.$script->id,
            'title' => $script->title,
            'client' => $client->name,
            'status' => $script->statusLabel(),
            'writer' => $writer->name,
            'sections' => array_map(fn ($s) => $s[0], $sections),
            'words' => array_sum(array_map(fn ($s) => str_word_count($s[1]), $sections)),
            'url' => route('scripts.edit', $script),
        ];
    }


    private function date(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (Throwable) {
            throw new ToolException('"'.$value.'" could not be read as a date. Use YYYY-MM-DD.');
        }
    }
}
