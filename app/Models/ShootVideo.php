<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One video captured on a shoot, as the crew member filed it at the time.
 *
 * Written only from the on-location runner (My\ShootRunController), never
 * from the producer's shoot form -- this is a record of what was actually
 * shot, and a row somebody typed in afterwards from memory would not be.
 */
class ShootVideo extends Model
{
    protected $fillable = [
        'shoot_id',
        'recorded_by_id',
        'name',
        'photo_path',
        'notes',
        'position',
    ];

    public function shoot(): BelongsTo
    {
        return $this->belongsTo(Shoot::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    /** Browser-reachable URL for the still, or null when none was taken. */
    public function photoUrl(): ?string
    {
        return $this->photo_path ? asset($this->photo_path) : null;
    }
}
