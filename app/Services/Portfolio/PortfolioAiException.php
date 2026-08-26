<?php

namespace App\Services\Portfolio;

use RuntimeException;

/**
 * Anthropic refused the call, or replied with something that was not the
 * JSON object asked for. Carries the provider's own message where there is
 * one -- same reasoning as InstagramException and CompetitorAnalysisException:
 * their wording usually names its own fix.
 */
class PortfolioAiException extends RuntimeException {}
