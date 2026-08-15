<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One AI client's key to one person's account.
 *
 * The plaintext exists for the length of one request -- the request that
 * creates it -- and is handed to the view to be shown once. Nothing else in the
 * app can read it back, by construction.
 */
class McpToken extends Model
{
    /**
     * Prefixed so it is recognisable on sight in a config file or a support
     * screenshot, and so a leaked one can be searched for.
     */
    public const PREFIX = 'chakra_';

    private const RANDOM_LENGTH = 40;

    /*
     * token_hash is not fillable: it is derived from a value only issue() ever
     * holds, and last_used_at is stamped by the middleware. Neither is anyone's
     * to post.
     */
    protected $fillable = ['user_id', 'name'];

    protected $casts = [
        'last_used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Mint a token for somebody, returning the row and the one and only copy of
     * the plaintext.
     *
     * @return array{token: McpToken, plain: string}
     */
    public static function issue(User $user, string $name): array
    {
        $plain = self::PREFIX.Str::random(self::RANDOM_LENGTH);

        $token = new self(['user_id' => $user->id, 'name' => $name]);
        $token->forceFill(['token_hash' => self::hash($plain)])->save();

        return ['token' => $token, 'plain' => $plain];
    }

    /**
     * Whoever this token belongs to, or null.
     *
     * Looked up by hash, so the plaintext is never compared against anything in
     * the database and a timing difference between rows reveals nothing -- the
     * index does an exact match on a value the attacker would already need to
     * know to compute.
     */
    public static function resolve(?string $plain): ?self
    {
        if (! is_string($plain) || ! str_starts_with($plain, self::PREFIX)) {
            return null;
        }

        return self::with('user')->where('token_hash', self::hash($plain))->first();
    }

    /**
     * Record that it was used, at most once a minute.
     *
     * An AI client makes many calls in quick succession, and a write on every
     * one of them would put a row update in front of every read for a field
     * nobody reads to the minute.
     */
    public function touchLastUsed(): void
    {
        if ($this->last_used_at?->gt(now()->subMinute())) {
            return;
        }

        $this->forceFill(['last_used_at' => now()])->save();
    }

    private static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
