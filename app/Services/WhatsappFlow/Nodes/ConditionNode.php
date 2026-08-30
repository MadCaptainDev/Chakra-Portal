<?php

namespace App\Services\WhatsappFlow\Nodes;

use App\Models\WhatsappFlowSession;
use Illuminate\Support\Arr;

/**
 * A two-way branch over the session's own variables -- the only node type
 * that reads `next_true`/`next_false` instead of a single `next`.
 *
 * Config: `variable` (dot-path into $session->variables), `operator`
 * (`equals` | `contains` | `exists`), `value` (ignored by `exists`),
 * `next_true`, `next_false`.
 */
class ConditionNode implements NodeHandler
{
    public function handle(WhatsappFlowSession $session, array $nodeConfig): NodeResult
    {
        $variables = $session->variables ?? [];
        $path = (string) ($nodeConfig['variable'] ?? '');
        $actual = Arr::get($variables, $path);

        $matches = match ($nodeConfig['operator'] ?? 'equals') {
            'exists' => Arr::has($variables, $path),
            'contains' => is_string($actual) && str_contains($actual, (string) ($nodeConfig['value'] ?? '')),
            default => $actual == $nodeConfig['value'], // 'equals' -- loose on purpose, config values arrive as strings
        };

        return NodeResult::advance($matches ? ($nodeConfig['next_true'] ?? null) : ($nodeConfig['next_false'] ?? null));
    }
}
