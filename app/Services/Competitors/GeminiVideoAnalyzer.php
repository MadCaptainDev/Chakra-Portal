<?php

namespace App\Services\Competitors;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Uploads a competitor reel's video to Gemini's File API and asks it for a
 * shot-by-shot breakdown, ported from the reference tool's `gemini.ts`.
 *
 * CALLED ONLY FROM app/Console/Commands/AnalyzeCompetitorReels.php. The
 * upload is a simple single-request one (Gemini's File API also offers a
 * two-step resumable protocol; this port keeps the reference implementation's
 * simpler one-shot form), but the poll afterwards can legitimately run up to
 * two minutes before the file is ACTIVE and analyzable -- not safe inside a
 * web request on a host with no queue worker.
 */
class GeminiVideoAnalyzer
{
    private const UPLOAD_URL = 'https://generativelanguage.googleapis.com/upload/v1beta/files';

    private const FILES_BASE = 'https://generativelanguage.googleapis.com/v1beta/';

    /** How long Gemini gets to move an upload from PROCESSING to ACTIVE. */
    private const MAX_WAIT_SECONDS = 120;

    private const POLL_INTERVAL_SECONDS = 3;

    /** One answer about a whole reel can take minutes on a thinking model. */
    private const ASK_SECONDS = 270;

    /** The model that answered last; the first of $models until one is retired. */
    public string $model;

    /** @param  string|array<int, string>  $models  tried in order; a retired one (404) moves on to the next */
    public function __construct(
        private readonly string $apiKey,
        private readonly string|array $models,
    ) {
        $this->model = ((array) $models)[0];
    }

    public static function make(): self
    {
        $settings = \App\Models\CompetitorSetting::current();

        return new self((string) $settings->geminiKey(), $settings->geminiModels());
    }

    /**
     * Upload the video, then block until Gemini reports it ACTIVE.
     *
     * @return array{uri: string, mimeType: string}
     */
    public function uploadVideo(string $videoBytes, string $mimeType): array
    {
        $response = Http::withHeaders([
            'x-goog-api-key' => $this->apiKey,
            'X-Goog-Upload-Command' => 'start, upload, finalize',
            'X-Goog-Upload-Header-Content-Length' => (string) strlen($videoBytes),
            'X-Goog-Upload-Header-Content-Type' => $mimeType,
        ])
            ->withBody($videoBytes, $mimeType)
            ->timeout(120)
            ->post(self::UPLOAD_URL);

        if ($response->failed()) {
            $this->throwFrom($response, 'upload');
        }

        $file = $response->json('file', []);
        $fileName = $file['name'] ?? null;
        $fileUri = $file['uri'] ?? null;
        $fileMimeType = $file['mimeType'] ?? $mimeType;

        if (! $fileName || ! $fileUri) {
            throw new CompetitorAnalysisException(
                'Gemini upload succeeded but returned no file name/uri.', provider: 'gemini',
            );
        }

        $this->waitForFileActive($fileName);

        return ['uri' => $fileUri, 'mimeType' => $fileMimeType];
    }

    /**
     * Poll the file's own status endpoint every 3 seconds until ACTIVE,
     * FAILED, or MAX_WAIT_SECONDS runs out. A failed poll request itself
     * (network blip) is treated as "still processing" rather than fatal --
     * one bad poll should not abort a video that is otherwise fine.
     */
    private function waitForFileActive(string $fileName): void
    {
        $deadline = now()->addSeconds(self::MAX_WAIT_SECONDS);

        while (now()->lessThan($deadline)) {
            $response = Http::withHeaders(['x-goog-api-key' => $this->apiKey])->timeout(15)->get(self::FILES_BASE.$fileName);

            if ($response->successful()) {
                $state = $response->json('state');

                if ($state === 'ACTIVE') {
                    return;
                }

                if ($state === 'FAILED') {
                    throw new CompetitorAnalysisException(
                        "Gemini file processing failed for {$fileName}.", provider: 'gemini',
                    );
                }
            }

            sleep(self::POLL_INTERVAL_SECONDS);
        }

        throw new CompetitorAnalysisException(
            "Gemini file {$fileName} did not become ACTIVE within ".self::MAX_WAIT_SECONDS.'s.',
            provider: 'gemini',
        );
    }

    /**
     * Ask Gemini to analyze the uploaded video. Exponential backoff on
     * failure (2s, 4s, 8s + jitter, so parallel videos in a batch do not
     * resync their retries), ported from the reference tool's backoffMs().
     *
     * Text after the first '#' only -- the reference tool's own prompts ask
     * for a markdown-headed breakdown and strip any preamble before it, and
     * this keeps that same output shape.
     */
    public function analyzeVideo(string $fileUri, string $mimeType, string $prompt, int $maxRetries = 3): string
    {
        $text = $this->ask($fileUri, $mimeType, $prompt, [], $maxRetries);
        $hashPosition = strpos($text, '#');

        return $hashPosition !== false ? substr($text, $hashPosition) : $text;
    }

    /**
     * Ask about the uploaded video and get Gemini's visible text back, raw.
     * Pass ['responseMimeType' => 'application/json'] for a JSON answer.
     *
     * @param  array<string, mixed>  $generationConfig
     */
    public function ask(string $fileUri, string $mimeType, string $prompt, array $generationConfig = [], int $maxRetries = 3): string
    {
        $models = array_values((array) $this->models);
        $this->model = $models[0];
        $url = self::generateUrl($this->model);

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            try {
                $response = \App\Services\Ai\GeminiBudget::during('competitor', fn () => Http::withHeaders(['x-goog-api-key' => $this->apiKey])
                    ->connectTimeout(15)->timeout(self::ASK_SECONDS)->asJson()->post($url, [
                        'contents' => [[
                            'role' => 'user',
                            'parts' => [
                                ['fileData' => ['fileUri' => $fileUri, 'mimeType' => $mimeType]],
                                ['text' => $prompt],
                            ],
                        ]],
                    ] + ($generationConfig ? ['generationConfig' => $generationConfig] : [])));
            } catch (\Illuminate\Http\Client\ConnectionException) {
                // Hung (overloaded): the next model, or one more try on the last.
                if ($next = $models[array_search($this->model, $models, true) + 1] ?? null) {
                    $this->model = $next;
                    $url = self::generateUrl($this->model);

                    continue;
                }
                if ($attempt < $maxRetries - 1) {
                    continue;
                }

                throw new CompetitorAnalysisException('Gemini took too long on every model (it is busy). Try again in a few minutes.', provider: 'gemini');
            }

            if ($response->successful()) {
                // Thinking models answer in several parts; join the visible ones.
                $text = collect((array) $response->json('candidates.0.content.parts', []))
                    ->reject(fn ($p) => ! empty($p['thought']))->pluck('text')->filter()->implode('');

                return $text;
            }

            // Retired (404), or this model's quota or capacity is used up (429/503):
            // try the next model straight away, without spending a retry.
            if (in_array($response->status(), [404, 429, 503], true) && ($next = $models[array_search($this->model, $models, true) + 1] ?? null)) {
                $this->model = $next;
                $url = self::generateUrl($this->model);
                $attempt--;

                continue;
            }

            if ($attempt < $maxRetries - 1) {
                usleep((int) (($this->backoffMs($attempt)) * 1000));

                continue;
            }

            $this->throwFrom($response, 'generateContent');
        }

        throw new CompetitorAnalysisException('Gemini analysis failed after retries.', provider: 'gemini');
    }

    private function backoffMs(int $attempt): int
    {
        return (int) (2000 * (2 ** $attempt)) + random_int(0, 500);
    }

    private static function generateUrl(string $model): string
    {
        return 'https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent';
    }

    private function throwFrom(\Illuminate\Http\Client\Response $response, string $context): never
    {
        $status = $response->status();
        $body = $response->body();

        $message = match (true) {
            $status === 429 => "Gemini rate limit (429) on model {$this->model}. This is a per-minute or "
                .'per-day quota on the Google AI key, not a bug — run fewer videos per batch, wait '
                .'(the daily quota resets at midnight Pacific), or turn on billing to raise the limits. '
                .'Current limits: https://aistudio.google.com/rate-limit',
            $status === 404 => "Gemini says model \"{$this->model}\" does not exist (404). Google retires "
                .'models — check Setup → Competitor Analysis and set gemini_model to one your key can reach.',
            $status === 400 && str_contains($body, 'API key not valid') => 'Gemini rejected the API key (400). '
                .'Check the Gemini key in Setup → Competitor Analysis. Get a key at https://aistudio.google.com/apikey',
            default => "Gemini error {$status} for {$context}.",
        };

        Log::error('Gemini call failed.', ['context' => $context, 'status' => $status]);

        throw new CompetitorAnalysisException($message.' Raw: '.$body, provider: 'gemini', status: $status);
    }
}
