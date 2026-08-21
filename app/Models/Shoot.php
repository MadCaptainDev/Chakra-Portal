<?php

namespace App\Models;

use App\Support\TimesheetVenture;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * NOT App\Models\NotionShoot -- that is a read-only mirror of Notion's
 * "Shoots" database, synced by ContentSyncService and never written to.
 * This is the portal's own first-class, writable shoot-booking record. The
 * two share a name by one word and nothing else; do not conflate them.
 */
class Shoot extends Model
{
    use HasFactory;

    public const STATUS_PLANNED = 'planned';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PLANNED => 'Planned',
        self::STATUS_CONFIRMED => 'Confirmed',
        self::STATUS_COMPLETED => 'Completed',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    protected $fillable = [
        'title',
        'client_id',
        'starts_at',
        'ends_at',
        'location',
        'status',
        'notes',
        'created_by_id',
        'notion_shoot_id',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * The Notion shoot this was imported from, or null when it was created
     * in the portal.
     *
     * Null is a real state, not missing data: the Notion token is
     * read-only, so a shoot created here cannot be pushed back and Notion
     * genuinely does not know about it. The Shoots screen says so rather
     * than implying the two are in step.
     */
    public function notionShoot(): BelongsTo
    {
        return $this->belongsTo(NotionShoot::class);
    }

    public function isFromNotion(): bool
    {
        return $this->notion_shoot_id !== null;
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function crew(): HasMany
    {
        return $this->hasMany(ShootCrew::class);
    }

    public function kits(): HasMany
    {
        return $this->hasMany(ShootKit::class);
    }

    public function scripts(): BelongsToMany
    {
        return $this->belongsToMany(Script::class, 'shoot_script');
    }

    /** Not cancelled, and not yet in the past. */
    public function scopeUpcoming(Builder $query): void
    {
        $query->where('status', '!=', self::STATUS_CANCELLED)
            ->where('starts_at', '>=', now()->startOfDay());
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('starts_at');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    public function clientLabel(): ?string
    {
        return $this->client ? TimesheetVenture::canonicalForClient($this->client) : null;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isPast(): bool
    {
        return $this->starts_at->isPast();
    }

    /**
     * Has the van been packed?
     *
     * Derived, never stored. A status column beside a set of timestamps that
     * can disagree will eventually disagree, and then neither is trusted.
     */
    public function isPacked(): bool
    {
        return $this->kits->isNotEmpty()
            && $this->kits->every(fn (ShootKit $row) => $row->checked_out_at !== null);
    }

    public function isFullyReturned(): bool
    {
        return $this->kits->isNotEmpty()
            && $this->kits->every(fn (ShootKit $row) => $row->returned_at !== null);
    }

    /** How the kit line reads on the list: "4 of 7 packed". */
    public function kitSummary(): string
    {
        $total = $this->kits->count();

        if ($total === 0) {
            return 'No kit listed';
        }

        if ($this->isFullyReturned()) {
            return 'All returned';
        }

        $packed = $this->kits->whereNotNull('checked_out_at')->count();

        return $packed === $total ? 'Kit packed' : $packed.' of '.$total.' packed';
    }

    /** Anything flagged missing or damaged on the way back. */
    public function hasKitProblems(): bool
    {
        return $this->kits->contains(fn (ShootKit $row) => $row->condition !== null);
    }
}
