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
}
