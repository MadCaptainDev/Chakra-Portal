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
 * With a Google Gemini key on file (Settings → Assistant) the video goes to
 * Gemini, which hears Tamil and Tanglish properly and writes all four
 * versions in one go. Without one, speech goes to Whisper on Groq (the
 * WhatsApp assistant's free key) and the assistant's Groq model cleans it
 * up -- noticeably weaker on Tamil: it mishears names and can flip a
 * "won't suit you" into "will look good". This host cannot run ffmpeg, so
 * nothing is converted here: the video is sent as it is.
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

    private const GEMINI = 'https://generativelanguage.googleapis.com';

    /**
     * Tried in order: Google retires models for new keys without notice
     * (2.5 Flash went that way), and the -latest alias always points at a
     * current Flash.
     */
    public const GEMINI_MODELS = ['gemini-3.8-flash', 'gemini-flash-latest'];

    /** Above this the video is uploaded to Gemini's file store instead of sent inline (inline requests cap at 20 MB, base64 included). */
    private const GEMINI_INLINE_BYTES = 14 * 1024 * 1024;

    private const GEMINI_PROMPT = 'This is a short Instagram reel from a video agency in Tamil Nadu, usually in Tamil or Tamil mixed with English. '
        .'Listen carefully to the SPEECH (ignore song lyrics in background music) and return JSON with: '
        .'language (the main spoken language, e.g. "Tamil"); '
        .'tamil (exactly what is said, Tamil in Tamil script, English words as English, one line per sentence); '
        .'tanglish (exactly what is said, Tamil written in English letters as people type it on WhatsApp, English words kept, one line per sentence); '
        .'english (a natural English translation, one line per sentence); '
        .'summary (one English line: what the reel is about); '
        .'seconds (the reel length in seconds, a whole number). '
        .'Do not add, guess or improve anything that was not said; write [unclear] for a word you cannot make out. '
        .'Keep product, brand and people names exactly as spoken.';

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

        if ($this->geminiKey()) {
            $path = $this->download($video);
            try {
                return $this->saveGemini($key, $url, $media, $by, $path, 'video/mp4');
            } finally {
                @unlink($path);
            }
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
        if ($this->geminiKey()) {
            return $this->saveGemini($key, null, null, $by, $file->getRealPath(), $file->getMimeType() ?: 'video/mp4');
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

    /** One call to Gemini with the video: all four versions back as JSON. */
    private function saveGemini(string $key, ?string $url, ?SocialMediaItem $media, ?User $by, string $path, string $mime): ReelTranscript
    {
        $apiKey = $this->geminiKey();
        $video = filesize($path) <= self::GEMINI_INLINE_BYTES
            ? ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode((string) file_get_contents($path))]]
            : ['file_data' => ['mime_type' => $mime, 'file_uri' => $this->geminiUpload($path, $mime, $apiKey)]];
        $text = 'Return JSON only.'.($media?->caption ? "\n\nThe post's caption, for context only (do not copy it into the transcript):\n".$media->caption : '');

        foreach (self::GEMINI_MODELS as $i => $model) {
            $response = Http::withHeaders(['x-goog-api-key' => $apiKey])->timeout(180)
                ->post(self::GEMINI.'/v1beta/models/'.$model.':generateContent', [
                    'system_instruction' => ['parts' => [['text' => self::GEMINI_PROMPT]]],
                    'contents' => [['parts' => [$video, ['text' => $text]]]],
                    'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0.1],
                ]);
            $retired = in_array($response->status(), [400, 404], true) && preg_match('/no longer available|not found|is not supported/i', (string) $response->json('error.message'));
            if (! $retired || $i === count(self::GEMINI_MODELS) - 1) {
                break;
            }
        }
        $reply = $this->json($response, 'Gemini');

        $out = json_decode((string) data_get($reply, 'candidates.0.content.parts.0.text', ''), true);
        if (! is_array($out) || blank($out['tanglish'] ?? null) && blank($out['tamil'] ?? null)) {
            $why = data_get($reply, 'candidates.0.finishReason') ?? data_get($reply, 'promptFeedback.blockReason');
            throw new RuntimeException('Gemini heard no speech in that reel'.($why ? " ({$why})" : '').'.');
        }
        $lines = fn ($v) => is_array($v) ? implode("\n", array_map('strval', $v)) : trim((string) $v);

        return ReelTranscript::updateOrCreate(['source_key' => $key], [
            'source_url' => $url ? mb_substr($url, 0, 500) : null,
            'social_media_item_id' => $media?->id,
            'language' => filled($out['language'] ?? null) ? mb_substr((string) $out['language'], 0, 40) : null,
            'duration_seconds' => is_numeric($out['seconds'] ?? null) ? (int) round($out['seconds']) : null,
            'transcript' => $lines($out['tamil'] ?? '') ?: null,
            'english' => $lines($out['english'] ?? '') ?: null,
            'tanglish' => $lines($out['tanglish'] ?? '') ?: null,
            'summary' => filled($out['summary'] ?? null) ? mb_substr(trim((string) $out['summary']), 0, 500) : null,
            'created_by_id' => $by?->id,
        ]);
    }

    /** A big video goes to Gemini's file store first (resumable upload), then waits until it is processed. */
    private function geminiUpload(string $path, string $mime, string $apiKey): string
    {
        $start = Http::withHeaders([
            'x-goog-api-key' => $apiKey,
            'X-Goog-Upload-Protocol' => 'resumable',
            'X-Goog-Upload-Command' => 'start',
            'X-Goog-Upload-Header-Content-Length' => (string) filesize($path),
            'X-Goog-Upload-Header-Content-Type' => $mime,
        ])->timeout(60)->post(self::GEMINI.'/upload/v1beta/files', ['file' => ['display_name' => 'reel']]);
        $uploadUrl = $start->header('X-Goog-Upload-URL') ?: throw new RuntimeException('Gemini would not take the upload: '.mb_substr($start->body(), 0, 200));

        $file = $this->json(Http::withHeaders(['X-Goog-Upload-Offset' => '0', 'X-Goog-Upload-Command' => 'upload, finalize'])
            ->withBody((string) file_get_contents($path), $mime)->timeout(180)->post($uploadUrl), 'Gemini upload')['file'] ?? [];

        for ($i = 0; ($file['state'] ?? 'ACTIVE') === 'PROCESSING' && $i < 30; $i++) {
            sleep(2);
            $file = $this->json(Http::withHeaders(['x-goog-api-key' => $apiKey])->timeout(30)->get(self::GEMINI.'/v1beta/'.$file['name']), 'Gemini upload');
        }

        return $file['uri'] ?? throw new RuntimeException('Gemini did not finish reading the video. Try again.');
    }

    /** The video, saved to a temporary file (Gemini needs the bytes, not a link). */
    private function download(string $url): string
    {
        $path = tempnam(sys_get_temp_dir(), 'reel');
        $response = Http::timeout(120)->sink($path)->get($url);
        if ($response->failed() || filesize($path) === 0) {
            @unlink($path);
            throw new RuntimeException('Could not download the video from that link.');
        }
        if (filesize($path) > 100 * 1024 * 1024) {
            @unlink($path);
            throw new RuntimeException('That video is over 100 MB.');
        }

        return $path;
    }

    private function geminiKey(): ?string
    {
        $settings = AiSetting::current();

        return $settings->hasGeminiKey() ? $settings->gemini_api_key : null;
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
