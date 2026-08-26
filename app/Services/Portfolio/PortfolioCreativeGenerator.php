<?php

namespace App\Services\Portfolio;

use App\Models\AiUsageLog;
use App\Models\CompetitorSetting;
use App\Models\PortfolioItem;
use App\Models\SocialMediaItem;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Writes the description + six creative-strategy fields (PortfolioItem::
 * CREATIVE_FIELDS) for a piece mapped to an Instagram post, from its
 * caption and performance numbers -- one call, same shape as
 * Competitors\ConceptGenerator (same provider, same "no PHP SDK" reasoning,
 * ported over raw HTTP), reusing the studio's one Anthropic key from
 * CompetitorSetting rather than asking for a second copy of it.
 *
 * Every call is logged to ai_usage_logs regardless of outcome on the
 * Anthropic side that reached us -- a failed *parse* of a successful
 * response still spent real tokens and must still show up as spend.
 */
class PortfolioCreativeGenerator
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    private const ANTHROPIC_VERSION = '2023-06-01';

    private const MODEL = 'claude-sonnet-5';

    private const MAX_TOKENS = 1024;

    public const PURPOSE = 'portfolio_creative';

    public function __construct(private readonly string $apiKey) {}

    public static function make(): self
    {
        return new self((string) CompetitorSetting::current()->anthropic_api_key);
    }

    /**
     * @return array{description: string, creative_hook: string, creative_concept: string,
     *     creative_storytelling: string, creative_cta: string, creative_offer: string, creative_audience: string}
     */
    public function generateFor(SocialMediaItem $media, string $clientName, ?PortfolioItem $item = null): array
    {
        $caption = trim((string) $media->caption) ?: '(no caption)';
        $views = $media->metricValue('views');
        $reach = $media->metricValue('reach');
        $metrics = collect(['views' => $views, 'reach' => $reach])->filter()->map(fn ($v, $k) => "{$k}: ".number_format($v))->implode(', ') ?: 'no performance numbers yet';

        $prompt = <<<PROMPT
        # ROLE
        You are a senior creative strategist at a video production studio, writing internal notes
        for a portfolio case study page.

        # INPUT
        Client: {$clientName}
        Post type: {$media->typeLabel()}
        Caption: {$caption}
        Performance: {$metrics}

        # TASK
        Reply with ONLY a JSON object (no prose before or after, no markdown code fence) with exactly
        these string keys:
        - "description": one or two sentences for the public portfolio page, written for a prospective
          client browsing the studio's work -- not a caption rewrite.
        - "creative_hook": what grabs attention in the first 1-2 seconds.
        - "creative_concept": the core creative idea in one sentence.
        - "creative_storytelling": how the narrative unfolds.
        - "creative_cta": the call to action used, or "None" if there genuinely is not one.
        - "creative_offer": any offer/promotion featured, or "None" if there genuinely is not one.
        - "creative_audience": who this was made to reach.

        Keep every value under 400 characters. Base every answer only on the caption and post type
        given -- do not invent details the caption does not support.
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
            self::PURPOSE,
            self::MODEL,
            (int) ($usage['input_tokens'] ?? 0),
            (int) ($usage['output_tokens'] ?? 0),
            $item,
        );

        return $this->parse($response->json('content.0.text') ?? '');
    }

    /**
     * @return array{description: string, creative_hook: string, creative_concept: string,
     *     creative_storytelling: string, creative_cta: string, creative_offer: string, creative_audience: string}
     */
    private function parse(string $text): array
    {
        // Claude was told not to fence the JSON, but a stray code fence
        // costs nothing extra to strip defensively.
        $text = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($text)) ?? $text);

        $decoded = json_decode($text, true);

        $required = ['description', 'creative_hook', 'creative_concept', 'creative_storytelling', 'creative_cta', 'creative_offer', 'creative_audience'];

        if (! is_array($decoded) || array_diff($required, array_keys($decoded)) !== []) {
            Log::error('PortfolioCreativeGenerator: unparseable response.', ['raw' => $text]);

            throw new PortfolioAiException('Anthropic did not reply with the expected fields. Try again, or fill these in by hand.');
        }

        return collect($decoded)->only($required)
            ->map(fn ($value) => trim((string) $value))
            ->all();
    }

    private function throwFrom(Response $response): never
    {
        $message = $response->json('error.message') ?? $response->body();

        Log::error('PortfolioCreativeGenerator: Anthropic call failed.', [
            'status' => $response->status(),
            'type' => $response->json('error.type'),
        ]);

        throw new PortfolioAiException("Anthropic error {$response->status()}: {$message}");
    }
}
