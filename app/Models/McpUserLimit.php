<?php

namespace App\Models;

use App\Tools\Tool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's MCP limits. Enforced in three places: AuthenticateMcpToken
 * (enabled), Mcp\Server::toolsFor (read_only, can_message_clients) and
 * Mcp\Server::call (daily_limit). The OAuth consent screen also refuses a
 * person whose access is off.
 */
class McpUserLimit extends Model
{
    protected $fillable = ['user_id', 'enabled', 'daily_limit', 'read_only', 'can_message_clients', 'updated_by_id'];

    protected $casts = [
        'enabled' => 'boolean',
        'daily_limit' => 'integer',
        'read_only' => 'boolean',
        'can_message_clients' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The saved row, or an unsaved one holding the defaults. */
    public static function for(User $user): self
    {
        return self::firstWhere('user_id', $user->id)
            ?? new self(['user_id' => $user->id, 'enabled' => true, 'daily_limit' => null, 'read_only' => false, 'can_message_clients' => true]);
    }

    public function allowsTool(Tool $tool): bool
    {
        if ($this->read_only && ! $tool->isReadOnly()) {
            return false;
        }

        return $this->can_message_clients || ! $tool->messagesClient();
    }

    public function callsToday(): int
    {
        return McpCallLog::where('user_id', $this->user_id)->where('created_at', '>=', today())->count();
    }

    public function isDefault(): bool
    {
        return $this->enabled && $this->daily_limit === null && ! $this->read_only && $this->can_message_clients;
    }
}
