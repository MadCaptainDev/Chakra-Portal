<?php

namespace App\Tools\Content;

use App\Models\User;
use App\Services\Ai\ReelTranscriber;
use App\Tools\Tool;
use App\Tools\ToolException;
use RuntimeException;

class TranscribeReel extends Tool
{
    public function name(): string
    {
        return 'transcribe_reel';
    }

    public function title(): string
    {
        return 'Transcribe a reel';
    }

    public function group(): string
    {
        return 'Scripts';
    }

    public function description(): string
    {
        return 'What a reel says: give its link and get the transcript in its own language (usually Tamil), an English '
            .'translation, a clean Tanglish version one line per sentence, and a one-line summary -- plus its caption and '
            .'views when it is one of our clients\' reels. Works for reels of the clients\' connected Instagram accounts '
            .'(not ones with licensed music, which Instagram will not hand over) and for any direct video link; someone '
            .'else\'s reel has to be uploaded on the portal\'s Reel Transcriber page. Results are kept, so asking again is '
            .'instant. USE WHEN asked "what does this reel say", to study a reel before writing a script, or to turn a '
            .'reel into a script.';
    }

    public function permission(): ?string
    {
        return 'scripts.view';
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
            'url' => ['type' => 'string', 'description' => 'The Instagram reel link, or a direct video link.'],
            'refresh' => ['type' => 'boolean', 'description' => 'Transcribe again even if it was done before.'],
        ], ['url']);
    }

    public function handle(array $arguments, User $user): array
    {
        try {
            $t = app(ReelTranscriber::class)->fromUrl((string) $arguments['url'], $user, (bool) ($arguments['refresh'] ?? false));
        } catch (RuntimeException $e) {
            throw new ToolException($e->getMessage());
        }
        $media = $t->socialMediaItem;

        return array_filter([
            'language' => $t->language,
            'seconds' => $t->duration_seconds,
            'summary' => $t->summary,
            'tanglish' => $t->tanglish,
            'english' => $t->english,
            'transcript' => $t->transcript,
            'reel' => $media ? array_filter([
                'client' => $media->socialAccount?->client?->name,
                'posted' => $media->posted_at?->toDateString(),
                'views' => $media->metricValue('views'),
                'caption' => $media->caption,
            ]) : null,
            'page' => route('reel-transcripts.show', $t),
        ], fn ($v) => $v !== null && $v !== '');
    }
}
