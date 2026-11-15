<?php

namespace App\Services\Competitors;

use App\Models\AiUsageLog;
use App\Models\CompetitorSetting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One call to Anthropic's Messages API, ported from the reference tool's
 * `claude.ts` (which used the official Node SDK; this is the same request
 * over raw HTTP, since there is no Anthropic PHP SDK in this project and
 * REDESIGN_PROMPT.md's "no new dependency" stance applies here too).
 *
 * Fast enough (one completion call) to trigger synchronously from a web
 * button, unlike GeminiVideoAnalyzer's CLI-only upload+poll step.
 */
class ConceptGenerator
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    private const ANTHROPIC_VERSION = '2023-06-01';

    private const MODEL = 'claude-sonnet-4-5-20250929';

    private const MAX_TOKENS = 4096;

    /** @param  string  $provider  "anthropic" or "gemini" */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $provider = "anthropic",
        private readonly string|array $geminiModel = "gemini-3.8-flash",
    ) {}

    /** Claude when its key is set; otherwise Gemini (this module's key, else the portal's). */
    public static function make(): self
    {
        $settings = CompetitorSetting::current();

        return $settings->hasAnthropic()
            ? new self((string) $settings->anthropic_api_key)
            : new self((string) $settings->geminiKey(), "gemini", $settings->geminiModels());
    }

    /**
     * $videoAnalysis is Gemini's shot-by-shot breakdown; $brandPrompt is
     * whatever the admin typed for this client's brand instructions. The
     * prompt template is the reference tool's own, verbatim -- it is the
     * one thing in that project actually worth keeping unchanged.
     */
    public function generateConcepts(string $videoAnalysis, string $brandPrompt): string
    {
        $prompt = <<<PROMPT
        # ROLE
        You're an expert in creating viral Reels on Instagram.

        # OBJECTIVE
        Take as input viral video from my competitor and based on it generate new concepts for me. Adapt this reference for me.

        # REFERENCE VIDEO DESCRIPTION
        ------
        {$videoAnalysis}
        ------

        # MY INSTRUCTIONS FOR NEW CONCEPTS
        ------
        {$brandPrompt}
        ------

        # OUTPUT
        Three concepts. For each: a title, the hook (first 2 seconds, word for word), the shot list
        with on-screen text, what the voice says, the call to action, and one line on why it should
        work for this brand. Keep what made the reference work; change everything that would make it
        a copy.

        # BEGIN YOUR WORK
        PROMPT;

        if ($this->provider === "gemini") {
            return $this->viaGemini($prompt);
        }

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => self::ANTHROPIC_VERSION,
        ])
            ->asJson()
            ->timeout(60)
            ->post(self::API_URL, [
                'model' => self::MODEL,
                'max_tokens' => self::MAX_TOKENS,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        if ($response->failed()) {
            $this->throwFrom($response);
        }

        $usage = $response->json('usage') ?? [];
        AiUsageLog::record(
            'competitor_concept',
            self::MODEL,
            (int) ($usage['input_tokens'] ?? 0),
            (int) ($usage['output_tokens'] ?? 0),
        );

        return $response->json('content.0.text') ?? '';
    }

    private function viaGemini(string $prompt): string
    {
        // A model Google has retired answers 404: move on to the next one.
        foreach ((array) $this->geminiModel as $model) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

            try {
                $response = \App\Services\Ai\GeminiBudget::during("competitor", fn () => Http::withHeaders(["x-goog-api-key" => $this->apiKey])->asJson()->connectTimeout(15)->timeout(180)->post($url, [
                    "contents" => [["role" => "user", "parts" => [["text" => $prompt]]]],
                    "generationConfig" => ["temperature" => 0.9, "maxOutputTokens" => 8192],
                ]));
            } catch (\App\Services\Ai\GeminiBudgetExceeded) {
                throw new CompetitorAnalysisException("This month's Gemini limit is used up. Raise it on the AI settings page.", provider: "gemini");
            }

            if ($response->status() !== 404) {
                break;
            }
        }

        if ($response->failed()) {
            Log::error("Gemini concept call failed.", ["status" => $response->status()]);
            throw new CompetitorAnalysisException(
                "Gemini error {$response->status()}: ".($response->json("error.message") ?? "no details"),
                provider: "gemini",
                status: $response->status(),
            );
        }

        $text = collect((array) $response->json("candidates.0.content.parts", []))->reject(fn ($p) => ! empty($p["thought"]))->pluck("text")->filter()->implode("");
        if (trim($text) === "") {
            throw new CompetitorAnalysisException("Gemini returned no concepts. Try again.", provider: "gemini");
        }

        return $text;
    }

    private function throwFrom(Response $response): never
    {
        $message = $response->json('error.message') ?? $response->body();

        Log::error('Anthropic call failed.', [
            'status' => $response->status(),
            'type' => $response->json('error.type'),
        ]);

        throw new CompetitorAnalysisException(
            "Anthropic error {$response->status()}: {$message}",
            provider: 'anthropic',
            status: $response->status(),
        );
    }
}
