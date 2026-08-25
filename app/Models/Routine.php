<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Template for a repeating studio duty. Occurrences are generated from this;
 * completion lives on RoutineOccurrence.
 */
class Routine extends Model
{
    use HasFactory;

    public const SCHEDULE_DAILY = 'daily';

    public const SCHEDULE_EVERY_N_DAYS = 'every_n_days';

    public const SCHEDULE_WEEKDAYS = 'weekdays';

    public const SCHEDULE_MONTHLY = 'monthly';

    public const SCHEDULES = [
        self::SCHEDULE_DAILY => 'Every day',
        self::SCHEDULE_EVERY_N_DAYS => 'Every N days',
        self::SCHEDULE_WEEKDAYS => 'Weekdays (Mon–Fri)',
        self::SCHEDULE_MONTHLY => 'Monthly (day of month)',
    ];

    public const MODE_SHARED = 'shared';

    public const MODE_INDIVIDUAL = 'individual';

    public const MODES = [
        self::MODE_SHARED => 'Shared (first doer wins)',
        self::MODE_INDIVIDUAL => 'Individual (each person)',
    ];

    /**
     * Umbrella flag on the routines row when any account subjects are in scope.
     * Concrete morph aliases live on routine_subjects / occurrences.
     */
    public const SUBJECT_ACCOUNTS = 'accounts';

    public const SUBJECT_SOCIAL = 'social_account';

    public const SUBJECT_CONTENT = 'content_account';

    public const SUBJECT_TYPES = [
        self::SUBJECT_ACCOUNTS => 'Client Instagram / Venture accounts',
    ];

    /**
     * Morph map aliases used on routine_subjects / occurrences.
     *
     * @var array<string, class-string<Model>>
     */
    public const SUBJECT_MORPH_MAP = [
        self::SUBJECT_SOCIAL => SocialAccount::class,
        self::SUBJECT_CONTENT => ContentAccount::class,
    ];

    protected $fillable = [
        'title',
        'description',
        'schedule_type',
        'schedule_interval',
        'day_of_month',
        'completion_mode',
        'subject_type',
        'is_active',
        'catch_up_days',
        'starts_on',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'starts_on' => 'date',
        'schedule_interval' => 'integer',
        'day_of_month' => 'integer',
        'catch_up_days' => 'integer',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function checkpoints(): HasMany
    {
        return $this->hasMany(RoutineCheckpoint::class)->orderBy('sort_order')->orderBy('id');
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(RoutineSubject::class);
    }

    public function fields(): HasMany
    {
        return $this->hasMany(RoutineField::class)->orderBy('sort_order')->orderBy('id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(RoutineOccurrence::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Fans out over Client Instagram / Venture accounts rather than being a
     * plain duty. Most routines are not -- cleaning the office is about
     * nothing.
     */
    public function isAccountScoped(): bool
    {
        return $this->subject_type === self::SUBJECT_ACCOUNTS;
    }

    /**
     * Why an active routine is producing nothing, in a sentence, or null when
     * it is fine.
     *
     * An account-scoped routine with no live accounts generates silently
     * nothing -- the generator returns an empty subject list and there is no
     * error anywhere. That is how the seeded DM/Comments duty can sit inactive
     * for weeks looking perfectly healthy. Surfacing it is the whole point.
     */
    public function generationWarning(): ?string
    {
        if (! $this->is_active || ! $this->isAccountScoped()) {
            return null;
        }

        $subjects = $this->relationLoaded('subjects') ? $this->subjects : $this->subjects()->get();

        if ($subjects->isEmpty()) {
            return 'Not generating: this routine is set to run per account, but no accounts are selected.';
        }

        if ($subjects->filter->isLive()->isEmpty()) {
            return 'Not generating: every account on this routine has been deleted or revoked.';
        }

        return null;
    }

    public function isShared(): bool
    {
        return $this->completion_mode === self::MODE_SHARED;
    }

    public function isIndividual(): bool
    {
        return $this->completion_mode === self::MODE_INDIVIDUAL;
    }

    public function scheduleLabel(): string
    {
        return match ($this->schedule_type) {
            self::SCHEDULE_EVERY_N_DAYS => 'Every '.max(1, (int) $this->schedule_interval).' days',
            self::SCHEDULE_MONTHLY => 'Monthly on day '.($this->day_of_month ?: $this->starts_on?->day),
            default => self::SCHEDULES[$this->schedule_type] ?? $this->schedule_type,
        };
    }

    /**
     * Resolve morph class for a stored subject_type alias.
     */
    public static function subjectClass(?string $alias): ?string
    {
        if ($alias === null) {
            return null;
        }

        return self::SUBJECT_MORPH_MAP[$alias] ?? null;
    }
}
