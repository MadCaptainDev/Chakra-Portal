<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person on one shoot, with their own call time — sound and camera rarely
 * arrive together.
 */
class ShootCrew extends Model
{
    protected $table = 'shoot_crew';

    protected $fillable = [
        'shoot_id',
        'user_id',
        'role',
        'call_time',
    ];

    public function shoot(): BelongsTo
    {
        return $this->belongsTo(Shoot::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
