<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing the competitor brain has learned ("Opening on a Tamil film meme
 * beats a plain talking head"), with the reels that back it. Each new reel
 * confirms (+1), contradicts (-1) or adds notes; a note that drops to 0 is
 * retired, not deleted, so the log still makes sense. See CompetitorBrain.
 */
class CompetitorBrainNote extends Model
{
    public const CATEGORIES = [
        'hook' => 'Hooks',
        'format' => 'Formats',
        'topic' => 'Topics',
        'like' => 'What gets likes',
        'share' => 'What gets shared',
        'audience' => 'Audience',
        'style' => 'Editing & style',
        'cta' => 'Calls to action',
        'avoid' => 'What falls flat',
    ];

    protected $fillable = ['competitor_account_id', 'category', 'insight', 'evidence', 'strength', 'status', 'last_confirmed_at'];

    protected $casts = ['evidence' => 'array', 'strength' => 'integer', 'last_confirmed_at' => 'datetime'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(CompetitorAccount::class, 'competitor_account_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    /** Notes about this account plus the ones true across all competitors; no account = every note. */
    public function scopeFor(Builder $q, ?int $accountId): Builder
    {
        return $q->when($accountId, fn ($q) => $q->where(fn ($w) => $w->whereNull('competitor_account_id')->orWhere('competitor_account_id', $accountId)));
    }
}
