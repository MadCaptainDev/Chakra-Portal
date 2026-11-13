<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person's OK on one version of a cut -- given by them, or for them by another approver ("approve for everyone", by_user_id). */
class ReviewApproval extends Model
{
    public $timestamps = false;

    protected $fillable = ['review_video_id', 'user_id', 'by_user_id', 'note', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function byUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'by_user_id');
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(ReviewVideo::class, 'review_video_id');
    }
}
