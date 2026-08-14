<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to one to-do, and when it happened.
 *
 * Nothing here is fillable. Every column is written through record(), so a form
 * can never claim that somebody else made a change, or that it happened at a
 * time it did not -- the same split that stops a post signing off a timesheet
 * day in another manager's name.
 */
class TodoUpdate extends Model
{
    /** The to-do was written down. */
    public const CREATED = 'created';

    /** Its status moved. */
    public const STATUS = 'status';

    /** Its due date was pushed. */
    public const MOVED = 'moved';

    /** Its wording or dates were edited. */
    public const EDITED = 'edited';

    /** @var array<string, string> */
    public const ACTIONS = [
        self::CREATED => 'Added',
        self::STATUS => 'Status changed',
        self::MOVED => 'Moved',
        self::EDITED => 'Edited',
    ];

    /**
     * Deliberately empty -- see the class docblock. record() is the only writer.
     *
     * @var list<string>
     */
    protected $fillable = [];

    protected $casts = [
        'from_on' => 'date',
        'to_on' => 'date',
    ];

    public function todo(): BelongsTo
    {
        return $this->belongsTo(Todo::class);
    }

    /** Who made the change. Null once that account has been deleted. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Write one history row.
     *
     * The change is force-filled from a fixed key set, so an array that has
     * picked up request input somewhere along the way cannot set a column this
     * method did not mean to.
     *
     * @param  array<string, mixed>  $change
     */
    public static function record(Todo $todo, ?User $actor, string $action, array $change = []): self
    {
        $allowed = ['from_status' => null, 'to_status' => null, 'from_on' => null, 'to_on' => null, 'note' => null];

        $update = new self;
        $update->forceFill(array_merge($allowed, array_intersect_key($change, $allowed), [
            'todo_id' => $todo->id,
            'user_id' => $actor?->id,
            'action' => $action,
        ]))->save();

        // Anything already holding the history -- the status replay, the
        // timeline -- should see this change without a second query.
        if ($todo->relationLoaded('updates')) {
            $todo->updates->push($update);
        }

        return $update;
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? ucfirst((string) $this->action);
    }

    /** The time of day this happened, for the per-day timeline. */
    public function timeLabel(): string
    {
        return $this->created_at->format('g:i A');
    }
}
