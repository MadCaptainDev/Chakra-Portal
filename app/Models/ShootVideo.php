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
        'script_id',
        'recorded_by_id',
        'name',
        'photo_path',
        'notes',
        'section_log',
        'seconds',
        'position',
    ];

    protected $casts = [
        'section_log' => 'array',
        'seconds' => 'integer',
    ];

    /** "2 of 3 sections · 4m 10s", or null when it was not shot from a script's sections. */
    public function sectionSummary(): ?string
    {
        if (! $this->section_log) {
            return null;
        }

        $shot = collect($this->section_log)->where('shot', true)->count();
        $total = count($this->section_log);
        $s = (int) $this->seconds;
        $time = $s >= 60 ? intdiv($s, 60).'m '.($s % 60).'s' : $s.'s';

        return "{$shot} of {$total} sections · {$time}";
    }

    public function shoot(): BelongsTo
    {
        return $this->belongsTo(Shoot::class);
    }

    /** The script it was shot from, when one was picked on set. */
    public function script(): BelongsTo
    {
        return $this->belongsTo(Script::class);
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
