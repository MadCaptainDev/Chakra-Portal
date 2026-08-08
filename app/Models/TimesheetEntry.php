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

    protected $fillable = [
        'user_id',
        'worked_on',
        'task',
        'venture',
        'started_at',
        'ended_at',
        'minutes',
        'status',
        'notes',
    ];

    protected $casts = [
        'worked_on' => 'date',
        'minutes' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForMonth(Builder $query, Carbon $month): void
    {
        $query->whereBetween('worked_on', [
            $month->copy()->startOfMonth()->toDateString(),
            $month->copy()->endOfMonth()->toDateString(),
        ]);
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
}
