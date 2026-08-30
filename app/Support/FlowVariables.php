<?php

namespace App\Support;

use App\Models\WhatsappFlowSession;

/**
 * Replace {{dot.path}} placeholders in flow message text.
 */
class FlowVariables
{
    public static function interpolate(string $text, WhatsappFlowSession $session): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([^}]+?)\s*\}\}/',
            fn (array $matches) => (string) data_get($session->variables ?? [], trim($matches[1]), ''),
            $text,
        );
    }
}
