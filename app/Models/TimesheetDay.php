<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One manager decision about one person's day.
 */
class TimesheetDay extends Model
{
    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const STATES = [
        self::APPROVED => 'Approved',
        self::REJECTED => 'Rejected',
    ];

    /*
     * Who decided and when are stamped by the action, never accepted from a
     * form -- otherwise a post could record that somebody else signed the day
     * off, which is the one fact this table exists to hold.
     */
    protected $fillable = [
        'user_id',
        'worked_on',
        'review_state',
        'review_note',
    ];

    protected $casts = [
        'worked_on' => 'date',
        'reviewed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    public function isApproved(): bool
    {
        return $this->review_state === self::APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->review_state === self::REJECTED;
    }

    public function stateLabel(): string
    {
        return self::STATES[$this->review_state] ?? ucfirst((string) $this->review_state);
    }

    /**
     * Half-open range rather than whereBetween, for the same reason
     * TimesheetEntry::scopeForMonth uses one: the cast writes a midnight time
     * onto a DATE column, which MySQL truncates and SQLite keeps, and
     * "2026-08-31 00:00:00" sorts after "2026-08-31".
     */
    public function scopeForMonth(Builder $query, Carbon $month): void
    {
        $query->where('worked_on', '>=', $month->copy()->startOfMonth()->toDateString())
            ->where('worked_on', '<', $month->copy()->startOfMonth()->addMonthNoOverflow()->toDateString());
    }

    /**
     * Every decision made about these people in this month, keyed
     * "userId|Y-m-d" -- the same key the timesheet screens group entries by, so
     * a day and its decision line up with one lookup rather than a query each.
     *
     * @param  Collection<int, int>|array<int, int>  $userIds
     * @return Collection<string, TimesheetDay>
     */
    public static function decisionsFor(Collection|array $userIds, Carbon $month): Collection
    {
        return self::whereIn('user_id', $userIds instanceof Collection ? $userIds->all() : $userIds)
            ->forMonth($month)
            ->get()
            ->keyBy(fn (self $day) => $day->user_id.'|'.$day->worked_on->toDateString());
    }
}
