<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoutineCheckpoint extends Model
{
    use HasFactory;

    protected $fillable = [
        'routine_id',
        'name',
        'sort_order',
    ];

    public function routine(): BelongsTo
    {
        return $this->belongsTo(Routine::class);
    }

    public function fields(): HasMany
    {
        return $this->hasMany(RoutineField::class, 'checkpoint_id')->orderBy('sort_order');
    }
}
