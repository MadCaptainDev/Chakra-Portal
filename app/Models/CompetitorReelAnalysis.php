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
        'language',
        'transcript',
        'hook',
        'format',
        'topic',
        'like_moments',
        'share_moments',
        'comment_read',
        'summary',
        'learned_at',
    ];

    protected $casts = [
        'analyzed_at' => 'datetime',
        'learned_at' => 'datetime',
        'transcript' => 'array',
        'like_moments' => 'array',
        'share_moments' => 'array',
        'comment_read' => 'array',
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
