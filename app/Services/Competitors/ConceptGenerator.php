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

    public function __construct(private readonly string $apiKey) {}

    public static function make(): self
    {
        return new self((string) CompetitorSetting::current()->anthropic_api_key);
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

        # BEGIN YOUR WORK
        PROMPT;

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
