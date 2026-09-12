<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing somebody did that the system could see for itself.
 *
 * Not a score and not a ranking -- see the migration for why this is kept
 * separate from EmployeePoint, which stays an admin's monthly judgement.
 */
class EmployeeRecognition extends Model
{
    public const KIND_TIMESHEET_ON_TIME = 'timesheet_on_time';

    public const KIND_CONTENT_ON_TIME = 'content_on_time';

    /**
     * Wording aimed at the person who earned it, not at whoever reads a
     * report about them.
     *
     * @var array<string, string>
     */
    public const KINDS = [
        self::KIND_TIMESHEET_ON_TIME => 'Timesheet on time',
        self::KIND_CONTENT_ON_TIME => 'Delivered on time',
    ];

    protected $fillable = [
        'user_id',
        'kind',
        'source_key',
        'earned_on',
        'points',
        'note',
    ];

    protected $casts = [
        'earned_on' => 'date',
        'points' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? 'Recognition';
    }

    /**
     * Half-open range, for the same reason TimesheetEntry::scopeForMonth()
     * uses one: a cast date column is written with a time on it, and the last
     * day of the month falls out of a naive whereBetween under SQLite.
     */
    public function scopeForMonth(Builder $query, Carbon $month): void
    {
        $query->where('earned_on', '>=', $month->copy()->startOfMonth()->toDateString())
            ->where('earned_on', '<', $month->copy()->startOfMonth()->addMonthNoOverflow()->toDateString());
    }
}
