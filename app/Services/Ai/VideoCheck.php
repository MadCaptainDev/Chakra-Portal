<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use App\Models\ReviewVideo;
use App\Services\Ai\CaptionWriter;
use Illuminate\Support\Facades\DB;
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

    /** A failure worth trying again later: Gemini busy or slow, not a broken file. */
    public const RETRY_ON = '/high demand|overloaded|unavailable|try again|timed out|timeout|slow|did not return|503|429|500/i';

    public const MAX_ATTEMPTS = 4;

    /** A check has this long in all, from the first piece sent to the report. */
    public const DEADLINE_MINUTES = 15;

    /** How long one worker holds a check before another may carry it on. */
    private const LEASE_SECONDS = 90;

    /** Asking Gemini is one call that can take minutes; its lease covers that. */
    private const ASK_SECONDS = 300;

    private const PIECE = 8 * 1024 * 1024;

    /**
     * Starts the check, or carries it on from where it got to. It runs in
     * steps (each piece of the upload, Gemini reading the file, Gemini
     * watching it) saved as they finish, so whoever comes next -- the
     * every-minute job or the review page's own polling -- picks it up
     * after a worker is cut off, instead of it sitting at "running".
     *
     * $budget > 0 (a web request) stops starting new steps after about that
     * many seconds; 0 (the command line) goes to the end.
     *
     * Only a cut's first version is checked. Later versions are reviewed
     * against the changes asked on the one before.
     */
    public static function run(ReviewVideo $video, int $budget = 0): void
    {
        $video->refresh();
        if (! config('ai.review.enabled', true)) {
            return;
        }
        if ($video->version > 1) {
            if (in_array($video->ai_status, ['queued', 'running', 'failed'], true)) {
                self::put($video, ['ai_status' => 'none', 'ai_stage' => null, 'ai_error' => null, 'ai_lease_until' => null]);
            }

            return;
        }
        if (in_array($video->ai_status, ['queued', 'failed', 'none'], true) && ! self::begin($video)) {
            return;
        }
        if ($video->ai_status === 'running') {
            self::work($video, $budget);
        }
    }

    /**
     * A check that should be worked on now: waiting to start, its worker
     * gone, or Gemini was busy a few minutes ago and it has tries left.
     */
    public static function wantsWork(ReviewVideo $video): bool
    {
        if ($video->version !== 1 || ! config('ai.review.enabled', true)) {
            return false;
        }

        return match ($video->ai_status) {
            'queued' => true,
            'running' => ! $video->ai_lease_until || $video->ai_lease_until->isPast(),
            'failed' => $video->ai_stage === 'retrying' && $video->ai_attempts < self::MAX_ATTEMPTS
                && $video->updated_at?->lte(now()->subMinutes(3)) && preg_match(self::RETRY_ON, (string) $video->ai_error) === 1,
            default => false,
        };
    }

    /** Checks past their time, with no worker left on them, are failed so the page stops waiting. */
    public static function expire(): int
    {
        return ReviewVideo::query()->where('ai_status', 'running')
            ->where(fn ($q) => $q->whereNull('ai_lease_until')->orWhere('ai_lease_until', '<', now()))
            ->where(fn ($q) => $q->where('ai_started_at', '<=', now()->subMinutes(self::DEADLINE_MINUTES))
                ->orWhere(fn ($q) => $q->whereNull('ai_started_at')->where('updated_at', '<=', now()->subMinutes(self::DEADLINE_MINUTES))))
            ->update(['ai_status' => 'failed', 'ai_stage' => null, 'ai_lease_until' => null, 'ai_upload_url' => null, 'ai_error' => self::tooLong(), 'updated_at' => now()]);
    }

    private static function tooLong(): string
    {
        return 'It took longer than '.self::DEADLINE_MINUTES.' minutes, so it was stopped. Press Check again to start over.';
    }

    /** The checks before starting, then the claim, so two callers never start the same cut. */
    private static function begin(ReviewVideo $video): bool
    {
        $settings = AiSetting::current();
        $skip = match (true) {
            ! $settings->hasGeminiKey() => 'Gemini is not connected.',
            ! $video->hasFile() => 'The file is gone.',
            $video->duration && $video->duration > (int) config('ai.review.max_seconds', 300) => 'Too long for the quick check.',
            $video->size > (int) config('ai.review.max_mb', 500) * 1024 * 1024 => 'Too large for the quick check.',
            default => null,
        };
        if ($skip) {
            self::put($video, ['ai_status' => 'skipped', 'ai_error' => $skip]);

            return false;
        }

        $claimed = ReviewVideo::query()->whereKey($video->id)->whereIn('ai_status', ['queued', 'failed', 'none'])->update([
            'ai_status' => 'running', 'ai_attempts' => DB::raw('ai_attempts + 1'),
            'ai_stage' => 'uploading', 'ai_progress' => 0, 'ai_stage_at' => now(), 'ai_error' => null,
            'ai_upload_url' => null, 'ai_upload_offset' => 0, 'ai_file' => null, 'ai_lease_until' => null,
            'ai_started_at' => now(), 'updated_at' => now(),
        ]);
        $video->refresh();

        return (bool) $claimed;
    }

    private static function work(ReviewVideo $video, int $budget): void
    {
        $lease = fn (int $secs) => ReviewVideo::query()->whereKey($video->id)->where('ai_status', 'running')
            ->where(fn ($q) => $q->whereNull('ai_lease_until')->orWhere('ai_lease_until', '<', now()))
            ->update(['ai_lease_until' => now()->addSeconds($secs)]);
        if (! $lease(self::LEASE_SECONDS + $budget)) {
            return; // someone else is on it
        }
        $video->refresh();
        if ($budget > 0) {
            ignore_user_abort(true);
            @set_time_limit(self::ASK_SECONDS + 120);
        }

        $key = AiSetting::current()->gemini_api_key;
        $until = $budget > 0 ? microtime(true) + $budget : INF;
        $resumed = true;

        try {
            while (true) {
                if ($video->ai_started_at && $video->ai_started_at->lte(now()->subMinutes(self::DEADLINE_MINUTES))) {
                    throw new CheckTooLong(self::tooLong());
                }
                if (microtime(true) >= $until) {
                    return;
                }

                if ($video->ai_stage === 'uploading') {
                    self::sendPiece($video, $key, $resumed);
                } elseif ($video->ai_stage === 'processing') {
                    if (! self::read($video, $key)) {
                        sleep(3);
                    }
                } elseif ($video->ai_stage === 'watching') {
                    self::put($video, ['ai_lease_until' => now()->addSeconds(self::ASK_SECONDS + 30)]);
                    $file = $video->ai_file;
                    $reply = GeminiBudget::during('review', fn () => self::ask($key, ['uri' => $file['uri'], 'mime' => $file['mimeType'] ?? ($video->mime ?: 'video/mp4')], self::prompt($video)), $video->uploadedBy);
                    self::stage($video, 'writing', 100);
                    self::finish($video, $reply);
                    self::forget($video, $key);

                    return;
                } else {
                    throw new RuntimeException('The check lost its place. Press Check again.');
                }
                $resumed = false;
            }
        } catch (GeminiBudgetExceeded) {
            self::forget($video, $key);
            self::put($video, ['ai_status' => 'skipped', 'ai_stage' => null, 'ai_error' => 'This month\'s Gemini limit is used up.']);
        } catch (Throwable $e) {
            if (! $e instanceof CheckTooLong) {
                report($e);
            }
            self::forget($video, $key);
            $retrying = ! $e instanceof CheckTooLong && preg_match(self::RETRY_ON, $e->getMessage())
                && (int) ReviewVideo::query()->whereKey($video->id)->value('ai_attempts') < self::MAX_ATTEMPTS;
            self::put($video, ['ai_status' => 'failed', 'ai_stage' => $retrying ? 'retrying' : null, 'ai_stage_at' => now(), 'ai_error' => Str::limit($e->getMessage(), 480)]);
        } finally {
            ReviewVideo::query()->whereKey($video->id)->update(['ai_lease_until' => null]);
        }
    }

    /** The report, saved. */
    private static function finish(ReviewVideo $video, array $reply): void
    {
        $report = self::json($reply);
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
            'ai_stage' => null,
        ])->save();
    }

    /**
     * The JSON report out of Gemini's reply: every text part joined (thinking
     * parts skipped), code fences stripped, and the outermost {...} taken
     * if anything was said around it.
     *
     * @return array<string, mixed>|null
     */
    public static function json(array $reply): ?array
    {
        $text = collect(data_get($reply, 'candidates.0.content.parts', []))
            ->reject(fn ($p) => ! empty($p['thought']))
            ->pluck('text')->filter()->implode('');
        $text = trim(preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text));

        $out = json_decode($text, true);
        if (! is_array($out) && preg_match('/\{.*\}/s', $text, $m)) {
            $out = json_decode($m[0], true);
        }

        return is_array($out) ? $out : null;
    }

    /** Written straight to the row, and to the model without marking it changed. */
    private static function put(ReviewVideo $video, array $values): void
    {
        ReviewVideo::query()->whereKey($video->id)->update(array_map(fn ($v) => is_array($v) ? json_encode($v) : $v, $values) + ['updated_at' => now()]);
        foreach ($values as $k => $v) {
            $video->{$k} = $v;
        }
        $video->syncOriginalAttributes(array_keys($values));
    }

    /** Where the check is up to, for the review page's progress. */
    private static function stage(ReviewVideo $video, string $stage, int $progress = 0, array $more = []): void
    {
        self::put($video, [
            'ai_stage' => $stage,
            'ai_progress' => max(0, min(100, $progress)),
            'ai_stage_at' => $video->ai_stage !== $stage ? now() : ($video->ai_stage_at ?? now()),
        ] + $more);
    }

    /**
     * One 8 MB piece of the upload to Gemini, and where it got to saved. A
     * worker carrying on from another first asks Gemini how much arrived.
     */
    private static function sendPiece(ReviewVideo $video, string $key, bool $resumed): void
    {
        $path = $video->filePath();
        $mime = $video->mime ?: 'video/mp4';
        $size = filesize($path);

        if (! $video->ai_upload_url) {
            $start = Http::withHeaders([
                'x-goog-api-key' => $key,
                'X-Goog-Upload-Protocol' => 'resumable',
                'X-Goog-Upload-Command' => 'start',
                'X-Goog-Upload-Header-Content-Length' => (string) $size,
                'X-Goog-Upload-Header-Content-Type' => $mime,
            ])->timeout(60)->post(self::API.'/upload/v1beta/files', ['file' => ['display_name' => 'review-'.$video->id]]);
            $url = $start->header('X-Goog-Upload-URL') ?: throw new RuntimeException('Gemini would not take the upload.');
            self::put($video, ['ai_upload_url' => $url, 'ai_upload_offset' => 0]);
        } elseif ($resumed && $video->ai_upload_offset > 0) {
            $asked = Http::withHeaders(['X-Goog-Upload-Command' => 'query'])->timeout(30)->post($video->ai_upload_url);
            if ($asked->header('X-Goog-Upload-Status') === 'final') {
                self::stage($video, 'processing', 0, ['ai_file' => $asked->json('file') ?? [], 'ai_upload_url' => null]);

                return;
            }
            if (is_numeric($got = $asked->header('X-Goog-Upload-Size-Received'))) {
                self::put($video, ['ai_upload_offset' => (int) $got]);
            }
        }

        $offset = (int) $video->ai_upload_offset;
        $handle = fopen($path, 'rb');
        try {
            fseek($handle, $offset);
            $chunk = (string) fread($handle, self::PIECE);
        } finally {
            fclose($handle);
        }
        $last = $offset + strlen($chunk) >= $size;
        $response = Http::withHeaders([
            'X-Goog-Upload-Offset' => (string) $offset,
            'X-Goog-Upload-Command' => $last ? 'upload, finalize' : 'upload',
        ])->withBody($chunk, $mime)->timeout(120)->post($video->ai_upload_url)->throw();

        $done = $offset + strlen($chunk);
        if ($last) {
            self::stage($video, 'processing', 0, ['ai_file' => $response->json('file') ?? [], 'ai_upload_url' => null, 'ai_upload_offset' => $done]);
        } else {
            self::stage($video, 'uploading', (int) round($done / max(1, $size) * 100), ['ai_upload_offset' => $done]);
        }
    }

    /** Has Gemini finished reading the file? On to watching when it has. */
    private static function read(ReviewVideo $video, string $key): bool
    {
        $file = (array) $video->ai_file;
        if (($file['state'] ?? 'PROCESSING') === 'PROCESSING' && filled($file['name'] ?? null)) {
            $file = Http::withHeaders(['x-goog-api-key' => $key])->timeout(30)->get(self::API.'/v1beta/'.$file['name'])->json() ?? [];
            self::put($video, ['ai_file' => $file]);
        }
        if (($file['state'] ?? '') === 'FAILED' || empty($file['name'])) {
            throw new RuntimeException('Gemini could not read the video file.');
        }
        if (($file['state'] ?? '') === 'ACTIVE' && filled($file['uri'] ?? null)) {
            self::stage($video, 'watching');

            return true;
        }

        return false;
    }

    /** Gemini's copy is not needed after the check, whatever happened. */
    private static function forget(ReviewVideo $video, ?string $key): void
    {
        $name = data_get($video->ai_file, 'name');
        if ($key && $name) {
            rescue(fn () => Http::withHeaders(['x-goog-api-key' => $key])->timeout(15)->delete(self::API.'/v1beta/'.$name), null, false);
        }
        self::put($video, ['ai_file' => null, 'ai_upload_url' => null]);
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
