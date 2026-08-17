<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class TimesheetEntry extends Model
{
    use HasFactory;

    /*
     * Legacy. Nothing writes these but the spreadsheet importer, which reads
     * them out of the old workbooks -- "Cacel" typo and all. Rows carrying
     * `cancelled` are still kept out of every hours total, because the work in
     * them genuinely did not happen. New entries are simply logged, and the day
     * they belong to is what a manager decides on.
     */
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CANCELLED = 'cancelled';

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

    /*
     * status is deliberately absent. It survives on old imported rows, where a
     * spreadsheet column said "Pending" or "Cacel", but nobody types it any
     * more: every day now goes to a manager, so an employee labelling their own
     * work "completed" told us nothing the review did not.
     */
    protected $fillable = [
        'user_id',
        'worked_on',
        'task',
        'task_type',
        'venture',
        'started_at',
        'ended_at',
        'minutes',
        'notes',
    ];

    protected $casts = [
        'worked_on' => 'date',
        'minutes' => 'integer',
        'was_backdated' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

    /**
     * The task types on offer, from master data.
     *
     * Keyed by SLUG, because the slug is what an entry stores. Renaming a term
     * relabels every past entry, which is what somebody tidying the list
     * expects; the slug is fixed at creation so the hours stay attached.
     *
     * TASK_TYPES is the fallback rather than the source, so a fresh database
     * -- or one mid-migration -- still has a working form instead of an empty
     * dropdown.
     *
     * @return array<string, string>
     */
    public static function taskTypes(): array
    {
        if (self::$taskTypes !== null) {
            return self::$taskTypes;
        }

        $types = null;

        try {
            $types = TaxonomyTerm::query()
                ->where('type', TaxonomyTerm::TYPE_TASK_TYPE)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->pluck('name', 'slug')
                ->all();
        } catch (\Throwable) {
            return self::$taskTypes = self::TASK_TYPES;
        }

        return self::$taskTypes = $types ?: self::TASK_TYPES;
    }

    /**
     * The per-request cache. A class property rather than a method static so
     * it can actually be cleared -- the master-data screen has to see its own
     * edit, and tests create terms and read the list back.
     *
     * @var array<string, string>|null
     */
    private static ?array $taskTypes = null;

    public static function flushTaskTypes(): void
    {
        self::$taskTypes = null;
    }

    public function taskTypeLabel(): string
    {
        // Falls back to the stored slug rather than "Other Task": an entry
        // logged against a type that has since been retired should say what it
        // was, not be quietly relabelled as something else.
        return self::taskTypes()[$this->task_type]
            ?? self::TASK_TYPES[$this->task_type]
            ?? Str::headline((string) $this->task_type ?: 'Other Task');
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
