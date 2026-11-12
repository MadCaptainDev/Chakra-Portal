<?php

namespace App\Services\Ai;

use RuntimeException;

/** This month's Gemini spend limit is reached; nothing was sent to Google. */
class GeminiBudgetExceeded extends RuntimeException {}
