<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class RoutineSubject extends Model
{
    use HasFactory;

    protected $fillable = [
        'routine_id',
        'subject_type',
        'subject_id',
    ];

    public function routine(): BelongsTo
    {
        return $this->belongsTo(Routine::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Whether this subject can still produce duties.
     *
     * An account deleted or revoked since the routine was configured stops
     * generating. The generator and Routine::generationWarning() both ask
     * here, so "what the generator will skip" and "what the admin is warned
     * about" cannot drift apart.
     */
    public function isLive(): bool
    {
        return match ($this->subject_type) {
            Routine::SUBJECT_SOCIAL => SocialAccount::query()
                ->forPlatform(SocialAccount::PLATFORM_INSTAGRAM)
                ->whereKey($this->subject_id)
                ->where('status', '!=', SocialAccount::STATUS_REVOKED)
                ->exists(),
            Routine::SUBJECT_CONTENT => ContentAccount::query()
                ->whereKey($this->subject_id)
                ->exists(),
            default => false,
        };
    }
}
