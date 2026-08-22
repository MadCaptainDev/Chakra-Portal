<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Claude-generated Reel concept, adapted from a competitor's analyzed
 * reel for a particular brand. See the migration for why brand_prompt is a
 * snapshot rather than a pointer to a client's brief.
 */
class GeneratedConcept extends Model
{
    protected $fillable = [
        'competitor_reel_analysis_id',
        'client_id',
        'brand_prompt',
        'concept_text',
        'generated_by_id',
        'generated_at',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
    ];

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(CompetitorReelAnalysis::class, 'competitor_reel_analysis_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_id');
    }
}
