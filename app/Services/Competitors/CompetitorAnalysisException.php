<?php

namespace App\Services\Competitors;

use RuntimeException;

/**
 * Something Apify, Gemini or Anthropic refused. Carries that provider's own
 * message, the same reasoning as InstagramException: their wording usually
 * names its own fix, and replacing it with a generic "request failed" throws
 * that away.
 */
class CompetitorAnalysisException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $provider,
        public readonly int $status = 0,
    ) {
        parent::__construct($message);
    }
}
