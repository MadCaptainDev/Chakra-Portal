<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One concrete duty owed on a date. Open rows stay open (overdue) until
 * done or skipped; generation never closes them.
 */
class RoutineOccurrence extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';
    public const STATUS_DONE = 'done';
    public const STATUS_SKIPPED = 'skipped';

    public const STATUSES = [
        self::STATUS_OPEN => 'Open',
        self::STATUS_DONE => 'Done',
        self::STATUS_SKIPPED => 'Skipped',
    ];

    protected $fillable = [
        'routine_id',
        'checkpoint_id',
        'subject_type',
        'subject_id',
        'assigned_user_id',
        'due_on',
        'status',
        'completed_by',
        'completed_at',
        'values',
        'note',
        'fingerprint',
    ];

    protected $casts = [
        'due_on' => 'date',
        'completed_at' => 'datetime',
        'values' => 'array',
    ];

    public function routine(): BelongsTo
    {
        return $this->belongsTo(Routine::class);
    }

    public function checkpoint(): BelongsTo
    {
        return $this->belongsTo(RoutineCheckpoint::class, 'checkpoint_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function completedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function scopeOpen(Builder $query): void
    {
        $query->where('status', self::STATUS_OPEN);
    }

    public function scopeOverdue(Builder $query): void
    {
        $query->open()->whereDate('due_on', '<', today()->toDateString());
    }

    public function scopeDueOnOrBefore(Builder $query, Carbon|string $day): void
    {
        $date = $day instanceof Carbon ? $day->toDateString() : $day;
        $query->open()->whereDate('due_on', '<=', $date);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_on->lt(today());
    }

    /**
     * Stable uniqueness key — NULL dimensions become empty segments so the
     * unique index works the same on MySQL and SQLite.
     */
    public static function fingerprintFor(
        int $routineId,
        ?int $checkpointId,
        ?string $subjectType,
        ?int $subjectId,
        ?int $assignedUserId,
        Carbon|string $dueOn,
    ): string {
        $due = $dueOn instanceof Carbon ? $dueOn->toDateString() : $dueOn;

        return implode('|', [
            $routineId,
            $checkpointId ?? 0,
            $subjectType ?? '',
            $subjectId ?? 0,
            $assignedUserId ?? 0,
            $due,
        ]);
    }

    /**
     * Overdue open occurrences for the admin badge / dashboard card.
     */
    public static function overdueCount(): int
    {
        return static::overdue()->count();
    }

    /**
     * Fields that apply to this occurrence (checkpoint-specific + shared).
     */
    public function applicableFields()
    {
        $routine = $this->routine;

        if (! $routine) {
            return collect();
        }

        return $routine->fields
            ->filter(fn (RoutineField $field) => $field->checkpoint_id === null
                || $field->checkpoint_id === $this->checkpoint_id)
            ->values();
    }

    /**
     * Human label for the polymorphic subject (Client IG handle or venture name).
     */
    public function subjectLabel(): ?string
    {
        $subject = $this->subject;

        if (! $subject) {
            return null;
        }

        if ($subject instanceof SocialAccount) {
            return $subject->handle();
        }

        if ($subject instanceof ContentAccount) {
            return $subject->name;
        }

        if (method_exists($subject, 'displayHandle')) {
            return $subject->displayHandle();
        }

        return $subject->name ?? null;
    }
}

