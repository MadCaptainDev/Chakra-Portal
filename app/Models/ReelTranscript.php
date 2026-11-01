<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One reel's words -- see App\Services\Ai\ReelTranscriber. */
class ReelTranscript extends Model
{
    protected $fillable = [
        'source_key',
        'source_url',
        'social_media_item_id',
        'language',
        'duration_seconds',
        'transcript',
        'english',
        'tanglish',
        'summary',
        'created_by_id',
    ];

    public function socialMediaItem(): BelongsTo
    {
        return $this->belongsTo(SocialMediaItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
