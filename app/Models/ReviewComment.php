<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A comment on a cut, pinned to a moment in it (or to the whole cut). */
class ReviewComment extends Model
{
    protected $fillable = ['review_video_id', 'parent_id', 'user_id', 'at_seconds', 'body', 'voice_path', 'voice_seconds', 'voice_peaks', 'voice_state', 'voice_attempts'];

    protected $casts = ['voice_seconds' => 'float', 'voice_peaks' => 'array', 'at_seconds' => 'float', 'resolved_at' => 'datetime', 'fix_checked_at' => 'datetime'];

    /** A voice note's file goes with it. (Replies cascade in the database, so forget() clears theirs first.) */
    protected static function booted(): void
    {
        static::deleting(fn (self $c) => $c->deleteVoice());
    }

    public function isVoice(): bool
    {
        return $this->voice_path !== null;
    }

    public function voicePath(): ?string
    {
        return $this->voice_path ? storage_path('app/private/'.$this->voice_path) : null;
    }

    public function deleteVoice(): void
    {
        if (($p = $this->voicePath()) && is_file($p)) {
            @unlink($p);
        }
    }

    /** The correction this became on the editor's card. */
    public function correction(): BelongsTo
    {
        return $this->belongsTo(Todo::class, 'correction_todo_id');
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(ReviewVideo::class, 'review_video_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('id');
    }

    /** People who agreed with it. */
    public function likers(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'review_comment_likes')->withPivot('created_at');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** "0:12", or null for a comment on the whole cut. */
    public function stamp(): ?string
    {
        return $this->at_seconds === null ? null : self::clock($this->at_seconds);
    }

    public static function clock(float $seconds): string
    {
        $s = (int) floor($seconds);

        return intdiv($s, 60).':'.str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT);
    }
}
