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
    /** What we call unless somebody chooses otherwise on the settings screen. */
    public const DEFAULT_MODEL = 'claude-opus-5';

    protected $fillable = [
        'api_key',
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

    public function modelName(): string
    {
        return filled($this->model) ? $this->model : self::DEFAULT_MODEL;
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }
}
