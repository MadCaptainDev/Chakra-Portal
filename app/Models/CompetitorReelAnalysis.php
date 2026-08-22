<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Gemini's shot-by-shot breakdown of one competitor reel. One row per reel
 * -- see the migration for why a re-analysis overwrites rather than stacks.
 */
class CompetitorReelAnalysis extends Model
{
    protected $fillable = [
        'competitor_reel_id',
        'breakdown',
        'gemini_model',
        'analyzed_at',
    ];

    protected $casts = [
        'analyzed_at' => 'datetime',
    ];

    public function reel(): BelongsTo
    {
        return $this->belongsTo(CompetitorReel::class, 'competitor_reel_id');
    }

    public function concepts(): HasMany
    {
        return $this->hasMany(GeneratedConcept::class);
    }
}
