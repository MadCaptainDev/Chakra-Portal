<?php

namespace App\Mcp;

use RuntimeException;

/**
 * "I understood you and could not do it."
 *
 * The message goes straight to the model, so it should read like something a
 * colleague would say -- what went wrong and what would work instead -- not
 * like a stack trace.
 */
class McpToolException extends RuntimeException {}
