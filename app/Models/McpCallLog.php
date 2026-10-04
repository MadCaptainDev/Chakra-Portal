<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A record of one MCP tool call. Written by Mcp\Server, read by the
 * Developer page. Arguments are cut to 2,000 characters -- a proposal
 * section or an ad report JSON is not worth keeping twice.
 */
class McpCallLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'mcp_token_id', 'tool', 'arguments', 'ok', 'error', 'duration_ms'];

    protected $casts = [
        'ok' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(McpToken::class, 'mcp_token_id');
    }

    /**
     * Never lets a logging failure break the call it is logging.
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function record(?User $user, ?McpToken $token, string $tool, array $arguments, bool $ok, ?string $error, int $durationMs): void
    {
        try {
            $json = json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

            static::create([
                'user_id' => $user?->id,
                'mcp_token_id' => $token?->id,
                'tool' => mb_substr($tool, 0, 80),
                'arguments' => mb_strlen($json) > 2000 ? mb_substr($json, 0, 2000).'…' : $json,
                'ok' => $ok,
                'error' => $error === null ? null : mb_substr($error, 0, 500),
                'duration_ms' => $durationMs,
            ]);
        } catch (Throwable $e) {
            Log::warning('MCP call log not written.', ['error' => $e->getMessage()]);
        }
    }
}
