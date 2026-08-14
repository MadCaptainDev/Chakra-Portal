<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One piece of work somebody means to do, spanning one day or several.
 *
 * The statuses are plain strings with a label map, the way every other status
 * in this app is done. Every transition is allowed, including going back:
 * people mis-tap, and blocked work unblocks. The history is the safety net, so
 * there is nothing here refusing a move.
 */
class Todo extends Model
{
    public const STATUS_WAITING = 'waiting';

    public const STATUS_STARTED = 'started';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * The keys are chosen to match what x-badge already styles for "completed"
     * and "cancelled", so those two need nothing added to the component.
     *
     * @var array<string, string>
     */
    public const STATUSES = [
        self::STATUS_WAITING => 'Waiting to Start',
        self::STATUS_STARTED => 'Started',
        self::STATUS_BLOCKED => 'Blocked',
        self::STATUS_COMPLETED => 'Completed',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    /** Still on somebody's plate. */
    public const OPEN_STATUSES = [self::STATUS_WAITING, self::STATUS_STARTED, self::STATUS_BLOCKED];

    /**
     * How a day's list reads top to bottom: what is in flight, then what is
     * stuck and needs somebody, then what has not been picked up, then the
     * settled work. Not the order the statuses are declared in -- that one is
     * the lifecycle, which is the wrong order for a board.
     *
     * @var list<string>
     */
    public const BOARD_ORDER = [
        self::STATUS_STARTED,
        self::STATUS_BLOCKED,
        self::STATUS_WAITING,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    /** Off it, one way or the other. */
    public const FINISHED_STATUSES = [self::STATUS_COMPLETED, self::STATUS_CANCELLED];

    /**
     * status and closed_on are missing on purpose. They move together, and only
     * through moveTo(), which also writes the history row that says when -- a
     * form posting a status straight in would leave the record with a hole.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'title',
        'notes',
        'starts_on',
        'due_on',
    ];

    /**
     * The column carries the same default, but a database default is only
     * applied on the way in -- the instance create() hands back would still
     * have a null status, and the history row written next to it would record
     * the to-do as starting from nowhere.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::STATUS_WAITING,
    ];

    protected $casts = [
        'starts_on' => 'date',
        'due_on' => 'date',
        'closed_on' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Oldest first, because the timeline is read top to bottom. */
    public function updates(): HasMany
    {
        return $this->hasMany(TodoUpdate::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * What was on the board on one day: it had started by then, and it had not
     * closed before then.
     *
     * Both halves are inequalities against a boundary rather than an equality
     * or a whereBetween, for the reason TimesheetEntry::scopeForMonth spells
     * out: these are DATE columns that the model casts, so Eloquent writes
     * "2026-08-14 00:00:00". MySQL truncates that on the way in; SQLite -- the
     * test database -- keeps the string, and "2026-08-14 00:00:00" sorts AFTER
     * "2026-08-14". A to-do starting today would drop off today's board under
     * the tests while production looked fine. Comparing against the first
     * instant of the next day is correct on both.
     *
     * closed_on >= day rather than = day is what keeps old days honest: work
     * finished on Friday was genuinely open on Monday, and Monday's board has
     * to still show it.
     */
    public function scopeOnDay(Builder $query, Carbon $day): void
    {
        $query->where('starts_on', '<', $day->copy()->addDay()->toDateString())
            ->where(fn (Builder $q) => $q
                ->whereNull('closed_on')
                ->orWhere('closed_on', '>=', $day->toDateString()));
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    /**
     * Where this sits in a day's list. Sorted in PHP rather than with an
     * ORDER BY FIELD(), which MySQL has and SQLite does not -- and a day's list
     * is a handful of rows, not something worth a portability problem over.
     */
    public function boardRank(): int
    {
        $rank = array_search($this->status, self::BOARD_ORDER, true);

        return $rank === false ? count(self::BOARD_ORDER) : $rank;
    }

    /**
     * The status this to-do had on a given day, replayed from its history.
     *
     * The board can be pointed at last Tuesday, and rendering today's status
     * against it would be an audit screen that lies -- "Started" beside a day
     * the thing sat untouched. Reads the loaded relation, so the board pays for
     * the history once and not once per row.
     */
    public function statusOn(Carbon $day): string
    {
        $cutoff = $day->copy()->startOfDay()->addDay();

        $last = $this->updates
            ->filter(fn (TodoUpdate $u) => $u->to_status !== null && $u->created_at->lt($cutoff))
            ->last();

        return $last?->to_status ?? self::STATUS_WAITING;
    }

    /** Past its promised day and still not done, as of the day being read. */
    public function isOverdueOn(Carbon $day): bool
    {
        return $this->isOpen() && $this->due_on->lt($day->copy()->startOfDay());
    }

    /**
     * How many days it is planned to take, counting both ends.
     *
     * Carbon 3's diffInDays returns a signed float, so the cast is not
     * decoration -- without it these leak "3.0" into the markup.
     */
    public function spanDays(): int
    {
        return (int) $this->starts_on->diffInDays($this->due_on) + 1;
    }

    /** Which day of that span the given date is -- "day 2 of 3". */
    public function dayOfSpan(Carbon $day): int
    {
        return max(1, (int) $this->starts_on->diffInDays($day->copy()->startOfDay()) + 1);
    }

    /**
     * Move to a new status, and write down when.
     *
     * closed_on is stamped from today rather than from whatever day the screen
     * is looking at -- finishing something is an act with a time, not an
     * assertion about the past. Reopening clears it, which is right: the work
     * is on somebody's plate again.
     */
    public function moveTo(string $status, ?User $actor = null, ?string $note = null): void
    {
        $from = $this->status;

        $this->forceFill([
            'status' => $status,
            'closed_on' => in_array($status, self::FINISHED_STATUSES, true) ? today()->toDateString() : null,
        ])->save();

        TodoUpdate::record($this, $actor, TodoUpdate::STATUS, [
            'from_status' => $from,
            'to_status' => $status,
            'note' => $note,
        ]);
    }

    /**
     * Push the promised day by one. Returns false if there was nothing to push.
     *
     * starts_on never moves. It is what the board is anchored to, so shifting
     * it would retroactively lift the item off the days it was actually worked.
     *
     * The lt(today()) branch is the part that is easy to get wrong: something
     * already two days overdue must land on tomorrow, not on a day that is also
     * in the past, where pressing the button would look like it did nothing.
     *
     * A started to-do stays started -- this changes a date, not a state.
     */
    public function defer(?User $actor = null, ?string $note = null): bool
    {
        if (! $this->isOpen()) {
            return false;
        }

        $from = $this->due_on->copy();
        $to = ($from->lt(today()) ? today() : $from->copy())->addDay();

        $this->forceFill(['due_on' => $to->toDateString()])->save();

        TodoUpdate::record($this, $actor, TodoUpdate::MOVED, [
            'from_on' => $from,
            'to_on' => $to,
            'note' => $note,
        ]);

        return true;
    }
}
