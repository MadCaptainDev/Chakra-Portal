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
        'trip_eta_at' => 'datetime',
        'trip_updated_at' => 'datetime',
        'returning_at' => 'datetime',
        'back_at_office_at' => 'datetime',
    ];

    /** After the wrap: straight to another shoot, or back to the office. */
    public const AFTER_NEXT_SHOOT = 'next_shoot';

    public const AFTER_OFFICE = 'office';

    public function nextShoot(): BelongsTo
    {
        return $this->belongsTo(Shoot::class, 'next_shoot_id');
    }

    /** Who is on the shoot is Notion's "Team": keep it in step. */
    protected static function booted(): void
    {
        $push = function (ShootCrew $row) {
            if (! \App\Services\Notion\NotionShootWriter::muted() && $row->shoot_id) {
                $id = $row->shoot_id;
                \Illuminate\Support\defer(fn () => ($shoot = Shoot::find($id)) && $shoot->notion_shoot_id
                    ? \App\Services\Notion\NotionShootWriter::update($shoot) : null, 'notion-team-'.$id);
            }
        };
        static::created($push);
        static::deleted($push);
    }

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
