<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;

/**
 * Credentials for the competitor-reel-analysis pipeline. One row, like
 * push_settings / notion_settings / whatsapp_settings.
 *
 * See the migration for why all three keys are encrypted (unlike
 * PushSetting's asymmetric split) -- none of these is ever shipped to a
 * browser.
 */
class CompetitorSetting extends Model
{
    protected $fillable = [
        'apify_token',
        'gemini_api_key',
        'anthropic_api_key',
        'gemini_model',
        'updated_by_id',
    ];

    protected $casts = [
        'apify_token' => 'encrypted',
        'gemini_api_key' => 'encrypted',
        'anthropic_api_key' => 'encrypted',
    ];

    /**
     * The one row, created empty on first use. Same retry-on-race shape as
     * PushSetting::current() -- two admins opening the settings screen at
     * once, one inserts, the other just reads what the winner wrote.
     */
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

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    public function hasApify(): bool
    {
        return filled($this->apify_token);
    }

    public function hasGemini(): bool
    {
        return filled($this->geminiKey());
    }

    /** This module's own Gemini key, else the portal's (AI settings). */
    public function geminiKey(): ?string
    {
        // The reel review's key first (one key, one monthly cap), then this module's own.
        return AiSetting::current()->gemini_api_key ?: ($this->gemini_api_key ?: null);
    }

    public function geminiModel(): string
    {
        return $this->geminiModels()[0];
    }

    /**
     * The reel review's models (config ai.review.models) first, as the user
     * asked, then the transcriber's, then the one set in Competitor setup;
     * a model Google retires falls through to the next.
     *
     * @return array<int, string>
     */
    public function geminiModels(): array
    {
        return array_values(array_unique(array_filter([
            ...(array) config('ai.review.models'),
            ...\App\Services\Ai\ReelTranscriber::GEMINI_MODELS,
            $this->gemini_model,
        ])));
    }

    /** Ideas need a writer: Claude when its key is set, otherwise Gemini. */
    public function canWriteConcepts(): bool
    {
        return $this->hasAnthropic() || $this->hasGemini();
    }

    public function hasAnthropic(): bool
    {
        return filled($this->anthropic_api_key);
    }

    /** Whether the whole pipeline -- scrape, analyze, generate -- can run. */
    public function isFullyConfigured(): bool
    {
        return $this->hasApify() && $this->hasGemini() && $this->hasAnthropic();
    }
}
