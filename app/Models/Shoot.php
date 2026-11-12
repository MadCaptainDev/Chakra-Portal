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
 * NOT App\Models\NotionShoot -- that is the local mirror of Notion's
 * "Shoots" database, filled by ContentSyncService. This is the portal's own
 * first-class shoot-booking record. The two share a name by one word and
 * nothing else; do not conflate them.
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

    /** Crew's tomorrow-evening reminder -- see SeedShootReminderTemplate and SendShootReminders. */
    public const WHATSAPP_TEMPLATE_REMINDER = 'shoot_reminder_v1';

    /** Staff alert for a completed shoot missing from the Reel Planner -- see SeedMissingContentAlertTemplate and SendMissingContentAlerts. */
    public const WHATSAPP_TEMPLATE_MISSING_CONTENT = 'shoot_content_missing_v1';

    /** Staff alert when the crew reach a shoot's location -- see SeedShootReachedTemplate and ShootReachedAlert. */
    public const WHATSAPP_TEMPLATE_REACHED = 'shoot_crew_reached_v1';

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
        'requested_at',
        'reminder_sent_at',
        'content_missing_alert_sent_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'requested_at' => 'datetime',
        'departed_at' => 'datetime',
        'reached_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'content_missing_alert_sent_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'kit_waived_at' => 'datetime',
        'footage_filed_at' => 'datetime',
        'late_start_alert_at' => 'datetime',
        'overrun_alert_at' => 'datetime',
        'footage_alert_at' => 'datetime',
        'kit_alert_at' => 'datetime',
    ];

    /**
     * A shoot set to cancelled from anywhere -- the producer's form, an MCP
     * tool, the Notion import -- gets its cancelled_at and its line on the
     * timeline. ShootRun::cancel() stamps its own, so this leaves it alone.
     */
    protected static function booted(): void
    {
        static::updating(function (Shoot $shoot) {
            if ($shoot->isDirty('status') && $shoot->status === self::STATUS_CANCELLED && $shoot->cancelled_at === null) {
                $shoot->cancelled_at = now();
                $shoot->cancelled_by_id = auth()->id();
                $shoot->noteCancelled = true;
            }
        });

        static::updated(function (Shoot $shoot) {
            if ($shoot->noteCancelled) {
                $shoot->noteCancelled = false;
                ShootEvent::record($shoot, auth()->user(), ShootEvent::CANCELLED);
            }

            // A portal change to what Notion shows goes to its page too.
            if ($shoot->wasChanged(['title', 'starts_at', 'ends_at', 'location', 'client_id', 'status'])
                && ! \App\Services\Notion\NotionShootWriter::muted()) {
                $fresh = $shoot->withoutRelations();
                \Illuminate\Support\defer(fn () => \App\Services\Notion\NotionShootWriter::update($fresh->fresh()));
            }
        });

        // Booked in the portal: made in Notion too, so the import never adds a second copy.
        static::created(function (Shoot $shoot) {
            if (! $shoot->notion_shoot_id && ! \App\Services\Notion\NotionShootWriter::muted()) {
                $fresh = $shoot->withoutRelations();
                \Illuminate\Support\defer(fn () => \App\Services\Notion\NotionShootWriter::create($fresh->fresh()));
            }
        });
    }

    /** Set between updating and updated; never a column. */
    public bool $noteCancelled = false;

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * The Notion shoot this was imported from, or null when it was created
     * in the portal.
     *
     * Null is a real state, not missing data: a shoot booked in the portal
     * has no Notion counterpart to create -- the integration is attached to
     * existing pages, not a writer of new ones -- so Notion genuinely does
     * not know about it. The Shoots screen says so rather than implying the
     * two are in step.
     *
     * Where one does exist, its status is writable: see
     * App\Services\Notion\NotionShootStatus, which moves the card when the
     * crew start and wrap.
     */
    public function notionShoot(): BelongsTo
    {
        return $this->belongsTo(NotionShoot::class);
    }

    public function isFromNotion(): bool
    {
        return $this->notion_shoot_id !== null;
    }

    /** Came in through the client's own portal, not booked by staff. */
    public function isRequestedByClient(): bool
    {
        return $this->requested_at !== null;
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

    /** Videos filed by the crew while this shoot was running, in order. */
    public function videos(): HasMany
    {
        return $this->hasMany(ShootVideo::class)->orderBy('position');
    }

    /** The day as it happened, oldest first -- see ShootEvent. */
    public function events(): HasMany
    {
        return $this->hasMany(ShootEvent::class)->orderBy('created_at')->orderBy('id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_id');
    }

    public function footageFiledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'footage_filed_by_id');
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_id');
    }

    public function reachedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reached_by_id');
    }

    public function departedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'departed_by_id');
    }

    /** The crew have set off; may or may not be there yet. */
    public function hasDeparted(): bool
    {
        return $this->departed_at !== null;
    }

    /** The crew are on location; may or may not be rolling yet. */
    public function hasReached(): bool
    {
        return $this->reached_at !== null;
    }

    /**
     * Happening right now -- started and not yet wrapped.
     *
     * Deliberately not a fifth STATUS_* value; see the
     * add_run_tracking_to_shoots_table migration for why.
     */
    public function isInProgress(): bool
    {
        // A shoot called off mid-run is over, whatever its clock says: left
        // "running" it would hold its crew off every other shoot for good.
        return $this->started_at !== null && $this->finished_at === null
            && $this->status !== self::STATUS_CANCELLED;
    }

    /** Started and wrapped. A shoot never booked at all is neither. */
    public function hasWrapped(): bool
    {
        return $this->finished_at !== null;
    }

    public function scopeInProgress(Builder $query): void
    {
        $query->whereNotNull('started_at')->whereNull('finished_at')
            ->where('status', '!=', self::STATUS_CANCELLED);
    }

    /** The booked end, or a working guess when the booking has none. */
    public function expectedEnd(): ?\Illuminate\Support\Carbon
    {
        return $this->ends_at
            ?? $this->starts_at?->copy()->addHours((int) config('shoots.late.default_hours', 4));
    }

    /** A date-only booking (midnight) has no start time to be late for. */
    public function hasStartTime(): bool
    {
        return $this->starts_at !== null
            && ! ($this->starts_at->hour === 0 && $this->starts_at->minute === 0);
    }

    /** The footage has been confirmed onto a drive. */
    public function hasFootageFiled(): bool
    {
        return $this->footage_filed_at !== null;
    }

    /** An admin let this shoot leave without its kit ticked. */
    public function hasKitWaived(): bool
    {
        return $this->kit_waived_at !== null;
    }

    /** Something went out and has not come back. */
    public function hasKitOut(): bool
    {
        return $this->kits->contains(fn (ShootKit $row) => $row->isCheckedOut());
    }

    /**
     * Completed, but nothing shows up for it in the Reel Planner -- either
     * this shoot has no Notion counterpart at all, or it does and that
     * counterpart's Shoot<->Reel relation (see ContentSyncService::
     * resolveShootLinks()) is empty. Either way: footage was shot, and
     * nobody has entered it into the content pipeline yet.
     */
    public function scopeNeedsContentAdded(Builder $query): void
    {
        $query->where('status', self::STATUS_COMPLETED)
            ->where(function (Builder $q) {
                $q->whereDoesntHave('notionShoot')
                    ->orWhereHas('notionShoot', fn (Builder $ns) => $ns->whereDoesntHave('contentItems'));
            });
    }

    /**
     * Same question as scopeNeedsContentAdded(), for one already-loaded
     * shoot -- needs notionShoot.contentItems eager-loaded to avoid an
     * extra query per row on a list.
     *
     * Named differently from the scope on purpose: Eloquent resolves a
     * real instance method before its scope-forwarding magic, so a
     * same-named instance method here would make every
     * Shoot::needsContentAdded() static call in this codebase fatal.
     */
    public function isMissingContent(): bool
    {
        return $this->status === self::STATUS_COMPLETED
            && ($this->notionShoot === null || $this->notionShoot->contentItems->isEmpty());
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
