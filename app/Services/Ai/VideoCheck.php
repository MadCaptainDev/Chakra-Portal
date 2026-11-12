<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use App\Models\ReviewVideo;
use App\Services\Ai\CaptionWriter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Gemini's quick check of a cut before a person reviews it: spelling in the
 * on-screen text, names, sound, picture, the hook, the script. It flags; a
 * reviewer decides. Inside the monthly Gemini limit (GeminiBudget) -- over
 * it, the check is skipped and the review goes ahead without it.
 */
class VideoCheck
{
    private const API = 'https://generativelanguage.googleapis.com';

    public static function run(ReviewVideo $video): void
    {
        if (! config('ai.review.enabled', true) || ! in_array($video->ai_status, ['queued', 'failed', 'none'], true)) {
            return;
        }

        $settings = AiSetting::current();
        if (! $settings->hasGeminiKey()) {
            $video->forceFill(['ai_status' => 'skipped', 'ai_error' => 'Gemini is not connected.'])->save();

            return;
        }
        if (! $video->hasFile()) {
            $video->forceFill(['ai_status' => 'skipped', 'ai_error' => 'The file is gone.'])->save();

            return;
        }
        if ($video->duration && $video->duration > (int) config('ai.review.max_seconds', 300)) {
            $video->forceFill(['ai_status' => 'skipped', 'ai_error' => 'Too long for the quick check.'])->save();

            return;
        }
        if ($video->size > (int) config('ai.review.max_mb', 500) * 1024 * 1024) {
            $video->forceFill(['ai_status' => 'skipped', 'ai_error' => 'Too large for the quick check.'])->save();

            return;
        }

        // Claimed, so two runs never check the same cut.
        if (! ReviewVideo::query()->whereKey($video->id)->whereIn('ai_status', ['queued', 'failed', 'none'])->update(['ai_status' => 'running', 'updated_at' => now()])) {
            return;
        }

        $key = $settings->gemini_api_key;
        $file = null;

        try {
            $file = self::upload($video, $key);
            $reply = GeminiBudget::during('review', fn () => self::ask($key, $file, self::prompt($video)), $video->uploadedBy);
            $report = json_decode((string) data_get($reply, 'candidates.0.content.parts.0.text', ''), true);
            if (! is_array($report) || ! isset($report['verdict'])) {
                throw new RuntimeException('Gemini did not return a report.');
            }

            $verdict = in_array($report['verdict'], array_keys(ReviewVideo::VERDICTS), true) ? $report['verdict'] : 'minor_fixes';
            $issues = collect($report['issues'] ?? [])->filter(fn ($i) => is_array($i) && filled($i['note'] ?? null))
                ->map(fn ($i) => [
                    'at' => is_numeric($i['at'] ?? null) ? max(0, round((float) $i['at'], 1)) : null,
                    'type' => Str::limit((string) ($i['type'] ?? 'other'), 20, ''),
                    'severity' => ($i['severity'] ?? '') === 'high' ? 'high' : 'low',
                    'note' => Str::limit(trim((string) $i['note']), 300),
                ])->take(15)->values()->all();

            $video->forceFill([
                'ai_status' => 'done',
                'ai_verdict' => $verdict,
                'ai_report' => [
                    'score' => is_numeric($report['score'] ?? null) ? max(1, min(10, (int) $report['score'])) : null,
                    'summary' => Str::limit(trim((string) ($report['summary'] ?? '')), 500),
                    'issues' => $issues,
                    'good' => collect($report['good'] ?? [])->map(fn ($g) => Str::limit(trim((string) $g), 160))->filter()->take(4)->values()->all(),
                ],
                'ai_error' => null,
                'ai_checked_at' => now(),
            ])->save();
        } catch (GeminiBudgetExceeded) {
            $video->forceFill(['ai_status' => 'skipped', 'ai_error' => 'This month\'s Gemini limit is used up.'])->save();
        } catch (Throwable $e) {
            report($e);
            $video->forceFill(['ai_status' => 'failed', 'ai_error' => Str::limit($e->getMessage(), 480)])->save();
        } finally {
            if ($file) {
                rescue(fn () => Http::withHeaders(['x-goog-api-key' => $key])->timeout(15)->delete(self::API.'/v1beta/'.$file['name']), null, false);
            }
        }
    }

    /** @return array{name: string, uri: string, mime: string} */
    private static function upload(ReviewVideo $video, string $key): array
    {
        $path = $video->filePath();
        $mime = $video->mime ?: 'video/mp4';

        $start = Http::withHeaders([
            'x-goog-api-key' => $key,
            'X-Goog-Upload-Protocol' => 'resumable',
            'X-Goog-Upload-Command' => 'start',
            'X-Goog-Upload-Header-Content-Length' => (string) filesize($path),
            'X-Goog-Upload-Header-Content-Type' => $mime,
        ])->timeout(60)->post(self::API.'/upload/v1beta/files', ['file' => ['display_name' => 'review-'.$video->id]]);
        $url = $start->header('X-Goog-Upload-URL') ?: throw new RuntimeException('Gemini would not take the upload.');

        $handle = fopen($path, 'rb');
        try {
            $file = Http::withHeaders(['X-Goog-Upload-Offset' => '0', 'X-Goog-Upload-Command' => 'upload, finalize'])
                ->withBody($handle, $mime)->timeout(600)->post($url)->throw()->json('file') ?? [];
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        for ($i = 0; ($file['state'] ?? 'ACTIVE') === 'PROCESSING' && $i < 60; $i++) {
            sleep(3);
            $file = Http::withHeaders(['x-goog-api-key' => $key])->timeout(30)->get(self::API.'/v1beta/'.$file['name'])->json() ?? [];
        }
        if (($file['state'] ?? '') !== 'ACTIVE' || empty($file['uri'])) {
            throw new RuntimeException('Gemini did not finish reading the video.');
        }

        return ['name' => $file['name'], 'uri' => $file['uri'], 'mime' => $file['mimeType'] ?? $mime];
    }

    /** @return array<string, mixed> */
    private static function ask(string $key, array $file, string $prompt): array
    {
        $models = (array) config('ai.review.models');

        foreach ($models as $i => $model) {
            $response = Http::withHeaders(['x-goog-api-key' => $key])->timeout(300)
                ->post(self::API.'/v1beta/models/'.$model.':generateContent', [
                    'contents' => [['role' => 'user', 'parts' => [
                        ['file_data' => ['mime_type' => $file['mime'], 'file_uri' => $file['uri']]],
                        ['text' => $prompt],
                    ]]],
                    'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0.2],
                ]);
            if ($response->successful()) {
                return $response->json();
            }
            $retired = in_array($response->status(), [400, 404], true) && preg_match('/no longer available|not found|is not supported/i', (string) $response->json('error.message'));
            $busy = in_array($response->status(), [429, 500, 503], true);
            if (! ($retired || $busy) || $i === count($models) - 1) {
                throw new RuntimeException('Gemini could not check the video: '.Str::limit((string) ($response->json('error.message') ?? $response->status()), 200));
            }
        }

        throw new RuntimeException('Gemini could not check the video.');
    }

    private static function prompt(ReviewVideo $video): string
    {
        $card = $video->todo;
        $item = $card?->contentItem;
        $client = $item ? CaptionWriter::clientFor($item) : null;
        $handle = $client ? CaptionWriter::usernameFor($client) : null;
        $kind = $card?->kind ?: 'reel';

        $context = array_filter([
            'Video' => $card?->title,
            'Client' => $item?->venture ?? $card?->venture,
            'Client legal/brand name' => $client?->name,
            'Instagram handle' => $handle ? '@'.$handle : null,
            'Goes out as' => $kind,
            'Script it should follow' => $item ? CaptionWriter::scriptOf($item) : null,
            'Caption planned' => $item?->caption ? Str::limit($item->caption, 800) : null,
        ]);

        return "You are the final quality checker at a video agency in Tamil Nadu, India, before a video goes out on Instagram for a client. "
            ."Watch and listen to the whole video and check it like a strict but fair senior editor.\n\n"
            .collect($context)->map(fn ($v, $k) => "## {$k}\n{$v}")->implode("\n\n")
            ."\n\n## Check\n"
            ."- Spelling and grammar of every on-screen text, title and subtitle (English and Tamil); names of the client, brand, doctor, products and places spelled exactly right.\n"
            ."- Sound: voice clear and audible, no clipping or distortion, no sudden silence, music not drowning the voice, no abrupt cut-offs at the end.\n"
            ."- Picture: no black, frozen or glitchy frames, no jump cuts that look like mistakes, nothing out of focus that should be sharp, colour consistent.\n"
            .($kind === 'reel' || $kind === 'story' ? "- Format: vertical 9:16; text and faces inside Instagram's safe area (not hidden behind the caption at the bottom or the buttons on the right).\n" : '')
            ."- The first 3 seconds: is there a clear hook?\n"
            .(isset($context['Script it should follow']) ? "- Script: anything important from the script missing, wrong or out of order.\n" : '')
            ."- Ending: a clear call to action or end card where it fits.\n"
            ."- Anything that would embarrass the client: wrong logo, wrong phone number, competitor names, a medical or price claim that looks wrong.\n\n"
            ."Do not invent problems. Give the time in seconds where each issue is seen or heard. If it is ready, say so.\n\n"
            .'Return JSON: {"verdict": "good_to_go" | "minor_fixes" | "needs_fixes", "score": 1-10, "summary": "one or two plain sentences", '
            .'"issues": [{"at": seconds, "type": "spelling|audio|visual|format|brand|script|hook|ending|other", "severity": "high|low", "note": "what is wrong and how to fix it"}], '
            .'"good": ["up to three things that work well"]}';
    }
}
