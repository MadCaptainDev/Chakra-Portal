<?php

namespace App\Tools;

use App\Models\Script;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateScript extends Tool
{
    /** Stages a model may move a script to; sending to the client goes through markSentToClient. */
    private const STATUSES = [
        Script::STATUS_DRAFT,
        Script::STATUS_WRITING,
        Script::STATUS_INTERNAL_REVIEW,
        Script::STATUS_CHANGES_REQUIRED,
        Script::STATUS_READY,
        Script::STATUS_CLIENT_REVIEW,
        Script::STATUS_COMPLETED,
        Script::STATUS_ARCHIVED,
    ];

    /** Stages that stand for the client's sign-off, guarded like the portal's approve button. */
    private const APPROVAL = [Script::STATUS_CLIENT_REVIEW, Script::STATUS_COMPLETED];

    public function name(): string
    {
        return 'update_script';
    }

    public function title(): string
    {
        return 'Edit a script';
    }

    public function group(): string
    {
        return 'Scripts';
    }

    public function description(): string
    {
        return 'Change a script by its number (#226): its title, its words, or its stage. Read it '
            .'with get_script first. New text REPLACES the whole script -- send all of it, as text '
            .'or as sections, with the words exactly as the person wants them (do not translate '
            .'or tidy Tanglish). Leave out what is not changing. A shoot that is rolling shows the '
            .'new words within seconds. Moving to client_review puts it in the client\'s approval '
            .'queue in their portal (no message is sent); completed means the client approved it.';
    }

    public function permission(): ?string
    {
        return 'scripts.edit';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function mcpOnly(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([
            'script' => ['type' => 'string', 'description' => 'The script number, e.g. "#226" or "226".'],
            'title' => ['type' => 'string', 'description' => 'A new title. Leave out to keep it.'],
            'text' => ['type' => 'string', 'description' => 'The whole new script. Blank lines separate paragraphs. Use this or sections.'],
            'sections' => [
                'type' => 'array',
                'description' => 'The whole new script in named parts, in order. Use this or text.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'heading' => ['type' => 'string'],
                        'text' => ['type' => 'string'],
                    ],
                    'required' => ['heading', 'text'],
                ],
            ],
            'status' => ['type' => 'string', 'enum' => self::STATUSES, 'description' => 'A new stage. Leave out to keep it.'],
        ], ['script']);
    }

    public function handle(array $arguments, User $user): array
    {
        $script = ScriptText::find($arguments['script'] ?? null);
        $sections = ScriptText::fromArguments($arguments, required: false);
        $title = isset($arguments['title']) ? trim((string) $arguments['title']) : null;
        $status = $arguments['status'] ?? null;

        if ($sections === null && ($title === null || $title === $script->title) && ($status === null || $status === $script->status)) {
            throw new ToolException('Nothing to change: send a new title, text or status.');
        }

        if ($title !== null) {
            if ($title === '' || mb_strlen($title) > 255) {
                throw new ToolException('A title must be 1 to 255 characters.');
            }
            $clash = Script::where('client_id', $script->client_id)->where('id', '!=', $script->id)
                ->whereRaw('LOWER(TRIM(title)) = ?', [mb_strtolower($title)])->first();
            if ($clash) {
                throw new ToolException("{$script->clientLabel()} already has a script called \"{$clash->title}\" (#{$clash->id}).");
            }
        }

        if ($status !== null) {
            if (! in_array($status, self::STATUSES, true)) {
                throw new ToolException('Unknown stage "'.$status.'".');
            }
            if (in_array($status, self::APPROVAL, true) && ! $user->can('scripts.approve')) {
                throw new ToolException('Sending a script to the client or marking it approved needs the Scripts approve permission.');
            }
            if ($status === Script::STATUS_CLIENT_REVIEW && ! $script->client_id) {
                throw new ToolException('This script has no client to send it to.');
            }
        }

        $changed = [];
        DB::transaction(function () use ($script, $sections, $title, $status, $user, &$changed) {
            if ($title !== null && $title !== $script->title) {
                $script->title = $title;
                $changed[] = 'title';
            }

            if ($sections !== null) {
                // Rewrite in place, position by position: kept rows bump
                // their version so an editor open in the portal sees a
                // conflict rather than saving over this.
                $existing = $script->sections()->orderBy('position')->orderBy('id')->get()->values();
                foreach ($sections as $i => [$heading, $text]) {
                    $row = $existing[$i] ?? $script->sections()->make();
                    $row->heading = $heading;
                    $row->position = $i;
                    $row->body = ScriptText::html($text);
                    $row->version = (int) $row->version + 1;
                    $row->save();
                }
                $existing->slice(count($sections))->each->delete();
                $changed[] = 'text';
            }

            if ($status !== null && $status !== $script->status) {
                if ($status === Script::STATUS_CLIENT_REVIEW) {
                    $script->save();
                    $script->markSentToClient();
                } else {
                    $script->status = $status;
                }
                $changed[] = 'status';
            }

            $script->save();
            // Also bumps updated_at, which is what a rolling shoot watches.
            $script->touchEditedBy($user);
        });

        $script->refresh()->load('sections');

        return [
            'number' => '#'.$script->id,
            'title' => $script->title,
            'client' => $script->clientLabel(),
            'status' => $script->statusLabel(),
            'changed' => $changed,
            'sections' => $script->sections->pluck('heading')->all(),
            'words' => str_word_count(ScriptText::plain($script->sections->pluck('body')->implode("\n\n"))),
            'url' => route('scripts.edit', $script),
        ];
    }
}
