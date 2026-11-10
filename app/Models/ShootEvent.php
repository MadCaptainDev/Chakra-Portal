<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing that happened on a shoot's day, who said so, and when.
 *
 * Nothing is fillable, for the same reason as TodoUpdate: every row is
 * written through record(), so a form can never claim that somebody else
 * picked up the kit or that the crew left at a time they did not.
 */
class ShootEvent extends Model
{
    public const KIT_PICKED = 'kit_picked';

    public const KIT_WAIVED = 'kit_waived';

    public const DEPARTED = 'departed';

    public const REACHED = 'reached';

    public const STARTED = 'started';

    public const VIDEO = 'video';

    public const WRAPPED = 'wrapped';

    /** Went straight on to another shoot (meta: next_shoot_id). */
    public const NEXT_SHOOT = 'next_shoot';

    public const RETURNING = 'returning';

    public const BACK_AT_OFFICE = 'back_at_office';

    public const KIT_RETURNED = 'kit_returned';

    public const FOOTAGE_FILED = 'footage_filed';

    public const CANCELLED = 'cancelled';

    /** The admins were told something is late (meta: kind). */
    public const LATE_ALERT = 'late_alert';

    /** @var array<string, string> */
    public const LABELS = [
        self::KIT_PICKED => 'Kit picked up',
        self::KIT_WAIVED => 'Left without kit check',
        self::DEPARTED => 'On the way',
        self::REACHED => 'Reached location',
        self::STARTED => 'Shoot started',
        self::VIDEO => 'Video shot',
        self::WRAPPED => 'Wrapped',
        self::NEXT_SHOOT => 'On to the next shoot',
        self::RETURNING => 'Returning to office',
        self::BACK_AT_OFFICE => 'Back at office',
        self::KIT_RETURNED => 'Kit returned',
        self::FOOTAGE_FILED => 'Footage on drive',
        self::CANCELLED => 'Cancelled',
        self::LATE_ALERT => 'Late alert sent',
    ];

    /** Tailwind text colours for the timeline dot and label. */
    public const TONES = [
        self::KIT_PICKED => 'text-violet-300',
        self::KIT_WAIVED => 'text-amber-300',
        self::DEPARTED => 'text-sky-300',
        self::REACHED => 'text-amber-200',
        self::STARTED => 'text-red-300',
        self::VIDEO => 'text-white/70',
        self::WRAPPED => 'text-emerald-300',
        self::NEXT_SHOOT => 'text-sky-300',
        self::RETURNING => 'text-sky-300',
        self::BACK_AT_OFFICE => 'text-emerald-300',
        self::KIT_RETURNED => 'text-violet-300',
        self::FOOTAGE_FILED => 'text-emerald-300',
        self::CANCELLED => 'text-red-300',
        self::LATE_ALERT => 'text-amber-300',
    ];

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    public function shoot(): BelongsTo
    {
        return $this->belongsTo(Shoot::class);
    }

    /** Who did it. Null for the system (a late alert) or a deleted account. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Write one row.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function record(Shoot $shoot, ?User $actor, string $type, ?string $note = null, array $meta = []): self
    {
        $event = new self;
        $event->forceFill([
            'shoot_id' => $shoot->id,
            'user_id' => $actor?->id,
            'type' => $type,
            'note' => $note !== null ? mb_substr($note, 0, 500) : null,
            'meta' => $meta ?: null,
            'created_at' => now(),
        ])->save();

        if ($shoot->relationLoaded('events')) {
            $shoot->events->push($event);
        }

        return $event;
    }

    public function label(): string
    {
        return self::LABELS[$this->type] ?? ucfirst(str_replace('_', ' ', (string) $this->type));
    }

    public function tone(): string
    {
        return self::TONES[$this->type] ?? 'text-white/60';
    }
}
