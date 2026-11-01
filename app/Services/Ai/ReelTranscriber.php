<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use App\Models\ReelTranscript;
use App\Models\SocialMediaItem;
use App\Models\User;
use App\Services\Instagram\InstagramGraph;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Reel link (or a video file) in, words out: the transcript in the reel's own
 * language, an English translation, and a Tanglish clean-up for the script
 * writers -- one line per sentence, mis-hearings fixed from the caption and
 * the translation -- with a one-line summary.
 *
 * Speech goes to Whisper on Groq (the same free-tier key as the WhatsApp
 * assistant); the clean-up to the assistant's own Groq model. This host
 * cannot run ffmpeg, so nothing is converted here: Groq fetches the video
 * from its link itself, or is sent the uploaded file as it is.
 *
 * Instagram serves the video only for reels of the clients' connected
 * accounts, and not even all of those -- a reel with licensed music comes
 * without its file. Anybody else's reel needs a direct video link or the
 * file. Results are kept: the same reel is never transcribed twice.
 */
class ReelTranscriber
{
    private const API = 'https://api.groq.com/openai/v1';

    private const SPEECH_MODEL = 'whisper-large-v3';

    /** Groq's free tier takes files up to 25 MB. */
    public const MAX_UPLOAD_KB = 25 * 1024;

    private const CLEANUP_PROMPT = 'You clean up speech-to-text of short Tamil/English Instagram reels for a video agency\'s script writer. '
        .'You get a rough transcript in the reel\'s language, an English translation, and sometimes the post caption. '
        .'Write the reel as spoken, in Tanglish (Tamil in English letters, English words kept as English), fixing obvious '
        .'mis-hearings using the caption and the translation for context. One line per sentence. Do not add anything that '
        .'was not said. If the reel is not in Tamil, write it in its own language in English letters. '
        .'Then a line with only ---, then a one-line English summary of the reel.';

    public function __construct(private InstagramGraph $graph) {}

    /** A reel link (Instagram, or a direct video link). */
    public function fromUrl(string $url, ?User $by = null, bool $refresh = false): ReelTranscript
    {
        $url = trim($url);
        if (! preg_match('#^https?://#i', $url)) {
            throw new RuntimeException('Give a full link, starting https://');
        }

        $media = null;
        if (preg_match('#instagram\.com/(?:[^/?]+/)?(?:reels?|p|tv)/([A-Za-z0-9_-]+)#i', $url, $m)) {
            $key = 'ig:'.$m[1];
            if (! $refresh && ($done = ReelTranscript::firstWhere('source_key', $key))) {
                return $done;
            }
            $media = SocialMediaItem::query()->with('socialAccount')->where('permalink', 'like', '%/'.$m[1].'/%')->first()
                ?? throw new RuntimeException('That reel is not from a connected client account, so Instagram will not hand over its video. '
                    .'Download it and upload the file on the Reel Transcriber page, or give a direct video link.');
            $video = $this->videoOf($media);
        } else {
            $key = 'url:'.sha1($url);
            if (! $refresh && ($done = ReelTranscript::firstWhere('source_key', $key))) {
                return $done;
            }
            $video = $url;
        }

        return $this->save($key, $url, $media, $by, fn (string $kind, array $fields) => $this->speech($kind, array_merge($fields, [['name' => 'url', 'contents' => $video]])));
    }

    /** An uploaded video or audio file (25 MB at most). */
    public function fromFile(UploadedFile $file, ?User $by = null): ReelTranscript
    {
        $key = 'file:'.sha1_file($file->getRealPath());
        if ($done = ReelTranscript::firstWhere('source_key', $key)) {
            return $done;
        }

        return $this->save($key, null, null, $by, fn (string $kind, array $fields) => $this->speech($kind, array_merge($fields, [[
            'name' => 'file', 'contents' => fopen($file->getRealPath(), 'r'), 'filename' => $file->getClientOriginalName() ?: 'reel.mp4',
        ]])));
    }

    private function save(string $key, ?string $url, ?SocialMediaItem $media, ?User $by, callable $speech): ReelTranscript
    {
        $heard = $speech('transcriptions', [['name' => 'response_format', 'contents' => 'verbose_json']]);
        $text = trim((string) ($heard['text'] ?? ''));
        if ($text === '') {
            throw new RuntimeException('No speech was heard in that reel (music only?).');
        }
        $language = (string) ($heard['language'] ?? '');
        $english = mb_strtolower($language) === 'english' ? $text : trim((string) ($speech('translations', [['name' => 'response_format', 'contents' => 'json']])['text'] ?? ''));
        [$tanglish, $summary] = $this->cleanUp($text, $english, $media?->caption);

        return ReelTranscript::updateOrCreate(['source_key' => $key], [
            'source_url' => $url ? mb_substr($url, 0, 500) : null,
            'social_media_item_id' => $media?->id,
            'language' => $language ?: null,
            'duration_seconds' => isset($heard['duration']) ? (int) round($heard['duration']) : null,
            'transcript' => $text,
            'english' => $english ?: null,
            'tanglish' => $tanglish,
            'summary' => $summary ? mb_substr($summary, 0, 500) : null,
            'created_by_id' => $by?->id,
        ]);
    }

    /** A fresh link to the reel's video file -- Instagram's links expire within days. */
    private function videoOf(SocialMediaItem $media): string
    {
        $token = $media->socialAccount?->access_token
            ?? throw new RuntimeException('That client\'s Instagram is not connected any more, so its video cannot be fetched.');
        try {
            $fresh = $this->graph->get($media->platform_media_id, $token, ['fields' => 'media_url']);
        } catch (Throwable $e) {
            throw new RuntimeException('Instagram would not give the video: '.$e->getMessage());
        }

        return $fresh['media_url'] ?? throw new RuntimeException(
            'Instagram does not hand over the video for this reel (it usually means it uses licensed music). Download it and upload the file instead.'
        );
    }

    /** @return array<string, mixed> */
    private function speech(string $kind, array $fields): array
    {
        $response = Http::withToken($this->key())->timeout(180)->asMultipart()
            ->post(self::API.'/audio/'.$kind, array_merge([['name' => 'model', 'contents' => self::SPEECH_MODEL]], array_values($fields)));

        return $this->json($response, 'Speech-to-text');
    }

    /** @return array{0: ?string, 1: ?string} the Tanglish lines and the summary */
    private function cleanUp(string $text, string $english, ?string $caption): array
    {
        $settings = AiSetting::current();
        if ($settings->providerName() !== AiSetting::PROVIDER_GROQ) {
            return [null, null];
        }
        try {
            $reply = $this->json(Http::withToken($this->key())->timeout(90)->post(self::API.'/chat/completions', [
                'model' => $settings->modelName(),
                'reasoning_effort' => 'low',
                'reasoning_format' => 'hidden',
                'messages' => [
                    ['role' => 'system', 'content' => self::CLEANUP_PROMPT],
                    ['role' => 'user', 'content' => ($caption ? "Caption: {$caption}\n\n" : '')."Transcript: {$text}\n\nEnglish translation: {$english}"],
                ],
            ]), 'Clean-up');
        } catch (Throwable $e) {
            report($e);

            return [null, null]; // the transcript and translation still stand
        }
        $out = trim((string) data_get($reply, 'choices.0.message.content', ''));
        [$lines, $summary] = array_pad(preg_split('/^\s*---\s*$/m', $out, 2), 2, null);

        return [trim((string) $lines) ?: null, $summary !== null ? trim($summary) : null];
    }

    private function key(): string
    {
        $settings = AiSetting::current();
        if ($settings->providerName() !== AiSetting::PROVIDER_GROQ || ! $settings->hasKey()) {
            throw new RuntimeException('The Reel Transcriber needs the Groq key (Settings → AI assistant).');
        }

        return $settings->api_key;
    }

    /** @return array<string, mixed> */
    private function json(Response $response, string $what): array
    {
        if ($response->failed()) {
            $why = (string) ($response->json('error.message') ?? $response->body());
            throw new RuntimeException("{$what} failed: ".mb_substr($why, 0, 200));
        }

        return (array) $response->json();
    }
}
