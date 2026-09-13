<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;

/**
 * The studio's Anthropic credentials. One row, like NotionSetting.
 *
 * Two separate questions this answers, and they are not the same question:
 * `hasKey()` is whether we *could* call the model, `isReady()` is whether we
 * *should*. Keeping them apart is what makes the off switch on the settings
 * screen safe to use -- see the migration's note.
 */
class AiSetting extends Model
{
    /**
     * Groq serves an open model on a free tier; Anthropic bills per token.
     * Groq is the default because the studio should not need a card on file
     * to ask about its own invoices.
     */
    public const PROVIDER_GROQ = 'groq';

    public const PROVIDER_ANTHROPIC = 'anthropic';

    /**
     * What each provider is called unless somebody types something else on
     * the settings screen. A model id is provider-specific -- sending Groq a
     * Claude id is a 404 -- so this is keyed by provider rather than being
     * one constant.
     */
    public const DEFAULT_MODELS = [
        self::PROVIDER_GROQ => 'openai/gpt-oss-120b',
        self::PROVIDER_ANTHROPIC => 'claude-opus-5',
    ];

    protected $fillable = [
        'api_key',
        'provider',
        'model',
        'is_active',
        'daily_answer_limit',
        'updated_by_id',
    ];

    protected $casts = [
        // Encrypted, not hashed: every call presents it, so it has to read
        // back. Same trade as NotionSetting::$api_key.
        'api_key' => 'encrypted',
        'is_active' => 'boolean',
        'last_answered_at' => 'datetime',
    ];

    /** The one row, created empty on first use. */
    public static function current(): self
    {
        if ($existing = static::query()->whereKey(1)->first()) {
            return $existing;
        }

        try {
            return static::forceCreate(['id' => 1]);
        } catch (QueryException $e) {
            return static::query()->whereKey(1)->first() ?? throw $e;
        }
    }

    public function hasKey(): bool
    {
        return filled($this->api_key);
    }

    /** On, keyed, and not already at today's ceiling. */
    public function isReady(): bool
    {
        return $this->is_active && $this->hasKey() && ! $this->atDailyLimit();
    }

    public function atDailyLimit(): bool
    {
        $limit = (int) $this->daily_answer_limit;

        if ($limit <= 0) {
            return false;
        }

        return AdminAgentMessage::query()
            ->where('role', AdminAgentMessage::ROLE_USER)
            ->whereDate('created_at', today())
            ->count() >= $limit;
    }

    public function providerName(): string
    {
        return array_key_exists((string) $this->provider, self::DEFAULT_MODELS)
            ? (string) $this->provider
            : self::PROVIDER_GROQ;
    }

    public function modelName(): string
    {
        return filled($this->model) ? $this->model : self::DEFAULT_MODELS[$this->providerName()];
    }

    /** Where the key comes from, for the settings screen to point at. */
    public function keyConsoleUrl(): string
    {
        return $this->providerName() === self::PROVIDER_ANTHROPIC
            ? 'console.anthropic.com'
            : 'console.groq.com';
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }
}
