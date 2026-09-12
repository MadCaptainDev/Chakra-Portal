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

    /*
     * confirmed_at is deliberately absent from $fillable: it is only ever set
     * by the person themselves confirming (App\Support\CrewPortal), never by
     * the producer's crew form, and a mass-assigned "confirmed" would be a
     * lie about who said so.
     */
    protected $casts = [
        'confirmed_at' => 'datetime',
    ];

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function shoot(): BelongsTo
    {
        return $this->belongsTo(Shoot::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
