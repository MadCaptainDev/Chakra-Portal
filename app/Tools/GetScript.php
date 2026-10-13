<?php

namespace App\Tools;

use App\Models\User;

class GetScript extends Tool
{
    public function name(): string
    {
        return 'get_script';
    }

    public function title(): string
    {
        return 'Read a script';
    }

    public function group(): string
    {
        return 'Scripts';
    }

    public function description(): string
    {
        return 'One script\'s full text by its number (#226 or 226), section by section, with '
            .'its client, stage and whether it has been shot. Read this before update_script '
            .'so an edit starts from what is there now.';
    }

    public function permission(): ?string
    {
        return 'scripts.view';
    }

    /** Whole scripts are long; the WhatsApp assistant has no use for this. */
    public function mcpOnly(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([
            'script' => ['type' => 'string', 'description' => 'The script number, e.g. "#226" or "226".'],
        ], ['script']);
    }

    public function handle(array $arguments, User $user): array
    {
        $script = ScriptText::find($arguments['script'] ?? null);

        return array_filter([
            'number' => '#'.$script->id,
            'id' => $script->id,
            'title' => $script->title,
            'client' => $script->clientLabel(),
            'status' => $script->statusLabel(),
            'writer' => $script->writer?->name,
            'shot' => $script->shot_at || $script->shootVideos()->exists() ?: null,
            'sections' => $script->sections
                ->map(fn ($s) => ['heading' => $s->heading, 'text' => ScriptText::plain($s->body)])
                ->values()->all(),
            'url' => route('scripts.edit', $script),
        ], fn ($v) => $v !== null);
    }
}
