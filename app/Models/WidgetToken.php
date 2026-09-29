<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One phone's read-only key to one person's "today" summary.
 *
 * The same shape as McpToken, deliberately kept apart from it: this key can
 * read GET /api/widget/today and nothing else. See the create_widget_tokens
 * migration for why.
 */
class WidgetToken extends Model
{
    // Different from McpToken's prefix, so neither resolves as the other.
    public const PREFIX = 'chakrawgt_';

    private const RANDOM_LENGTH = 40;

    protected $fillable = ['user_id', 'name'];

    protected $casts = [
        'last_used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array{token: WidgetToken, plain: string}
     */
    public static function issue(User $user, string $name): array
    {
        $plain = self::PREFIX.Str::random(self::RANDOM_LENGTH);

        $token = new self(['user_id' => $user->id, 'name' => $name]);
        $token->forceFill(['token_hash' => self::hash($plain)])->save();

        return ['token' => $token, 'plain' => $plain];
    }

    public static function resolve(?string $plain): ?self
    {
        if (! is_string($plain) || ! str_starts_with($plain, self::PREFIX)) {
            return null;
        }

        return self::with('user')->where('token_hash', self::hash($plain))->first();
    }

    /** At most one write every ten minutes -- a widget refreshes on its own all day. */
    public function touchLastUsed(): void
    {
        if ($this->last_used_at?->gt(now()->subMinutes(10))) {
            return;
        }

        $this->forceFill(['last_used_at' => now()])->save();
    }

    private static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
