<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class TimesheetEntry extends Model
{
    use HasFactory;

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_COMPLETED => 'Completed',
        self::STATUS_PENDING => 'Pending',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    public const TASK_SHOOTING = 'shooting';

    public const TASK_EDITING = 'editing';

    public const TASK_POSTING = 'posting';

    public const TASK_OTHER = 'other';

    public const TASK_TYPES = [
        self::TASK_SHOOTING => 'Shooting',
        self::TASK_EDITING => 'Editing',
        self::TASK_POSTING => 'Posting',
        self::TASK_OTHER => 'Other Task',
    ];

    protected $fillable = [
        'user_id',
        'worked_on',
        'task',
        'task_type',
        'venture',
        'started_at',
        'ended_at',
        'minutes',
        'status',
        'notes',
    ];

    /*
     * The review columns are deliberately absent from $fillable. They are
     * written only by the admin review actions, which set them explicitly --
     * so no amount of extra form input on the employee's own edit screen can
     * approve an entry or clear a manager's question.
     */
    protected $casts = [
        'worked_on' => 'date',
        'minutes' => 'integer',
        'reviewed_at' => 'datetime',
        'was_backdated' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_REJECTED = 'rejected';

    public const REVIEW_QUERIED = 'queried';

    public const REVIEW_STATES = [
        self::REVIEW_APPROVED => 'Approved',
        self::REVIEW_REJECTED => 'Rejected',
        self::REVIEW_QUERIED => 'Question asked',
    ];

    /** A manager has asked something and is waiting on a reply. */
    public function isQueried(): bool
    {
        return $this->review_state === self::REVIEW_QUERIED;
    }

    public function isApproved(): bool
    {
        return $this->review_state === self::REVIEW_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->review_state === self::REVIEW_REJECTED;
    }

    /**
     * Is a manager's decision outstanding?
     *
     * Only late-filed and edited entries need one. An entry written for the day
     * it happened is taken at face value -- asking a manager to sign off work
     * logged on the day it was done is ceremony, and ceremony is what stops
     * people filling in timesheets at all.
     */
    public function needsReview(): bool
    {
        return $this->was_backdated && $this->review_state === null;
    }

    public function reviewStateLabel(): ?string
    {
        return $this->review_state
            ? (self::REVIEW_STATES[$this->review_state] ?? ucfirst($this->review_state))
            : null;
    }

    /**
     * Was this entry filed for a day that had already passed?
     *
     * Stamped once, at creation. Deriving it later by comparing worked_on to
     * today would relabel every entry in the system as backdated by the
     * following morning.
     */
    public static function isLateFor(?string $workedOn): bool
    {
        return $workedOn !== null && Carbon::parse($workedOn)->startOfDay()->lt(now()->startOfDay());
    }

    /**
     * Wipe the review. Called when the employee edits the entry: the thing a
     * manager approved or asked about no longer exists in that form, so the
     * decision has to be made again against what the entry says now -- and an
     * edited entry always needs one, whichever day it was for.
     */
    public function clearReview(): void
    {
        $this->forceFill([
            'reviewed_at' => null,
            'reviewed_by_id' => null,
            'review_note' => null,
            'review_state' => null,
            'was_backdated' => true,
        ]);
    }

    /**
     * Half-open range rather than whereBetween on two date strings.
     *
     * worked_on is a DATE column, but the model casts it, so Eloquent writes
     * "2026-08-31 00:00:00". MySQL truncates that to a date on the way in and
     * whereBetween behaves; SQLite keeps the string, and "2026-08-31 00:00:00"
     * sorts AFTER "2026-08-31" -- so the last day of the month fell out of
     * every month query under the test database while production looked fine.
     * Comparing against the first instant of the next month is correct on both.
     */
    public function scopeForMonth(Builder $query, Carbon $month): void
    {
        $query->where('worked_on', '>=', $month->copy()->startOfMonth()->toDateString())
            ->where('worked_on', '<', $month->copy()->startOfMonth()->addMonthNoOverflow()->toDateString());
    }

    public function scopeCounted(Builder $query): void
    {
        // Cancelled work still gets logged, but must not inflate hours.
        $query->where('status', '!=', self::STATUS_CANCELLED);
    }

    /**
     * Minutes between two 24-hour times.
     *
     * Returns null when either is missing, so the caller keeps whatever
     * duration was entered by hand. A finish earlier than the start is read as
     * running past midnight rather than as an error -- late edits genuinely do.
     */
    public static function minutesBetween(?string $start, ?string $end): ?int
    {
        if (! $start || ! $end) {
            return null;
        }

        $from = Carbon::createFromFormat('H:i', substr($start, 0, 5));
        $to = Carbon::createFromFormat('H:i', substr($end, 0, 5));

        if ($to->lessThan($from)) {
            $to->addDay();
        }

        return (int) $from->diffInMinutes($to);
    }

    /**
     * "16 hrs 30 mins" -- matching how the team already writes durations.
     */
    public function durationLabel(): string
    {
        return self::formatMinutes($this->minutes);
    }

    public static function formatMinutes(int $minutes): string
    {
        if ($minutes <= 0) {
            return '—';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        $parts = [];

        if ($hours > 0) {
            $parts[] = $hours.' '.($hours === 1 ? 'hr' : 'hrs');
        }

        if ($rest > 0) {
            $parts[] = $rest.' '.($rest === 1 ? 'min' : 'mins');
        }

        return implode(' ', $parts);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    public function taskTypeLabel(): string
    {
        return self::TASK_TYPES[$this->task_type] ?? 'Other Task';
    }

    /**
     * Guess shooting / editing / posting / other from a free-text task name.
     * Used for import backfill and optional form suggestions.
     */
    public static function inferTaskType(?string $task): string
    {
        $haystack = mb_strtolower(trim((string) $task));

        if ($haystack === '') {
            return self::TASK_OTHER;
        }

        if (preg_match('/\b(shoot|shooting|photo\s*shoot)\b/u', $haystack)) {
            return self::TASK_SHOOTING;
        }

        if (preg_match('/\b(edit|editing|edits)\b/u', $haystack)) {
            return self::TASK_EDITING;
        }

        if (preg_match('/\b(post|posting|upload|uploading|schedule|scheduling|publish|publishing)\b/u', $haystack)) {
            return self::TASK_POSTING;
        }

        return self::TASK_OTHER;
    }
}
