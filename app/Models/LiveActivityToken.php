<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An iPhone Live Activity push token. push_to_start: one per device and
 * activity type (iOS 17.2+), lets the portal start the activity. update:
 * one per running activity (activity_id), lets it update and end it.
 */
class LiveActivityToken extends Model
{
    public const TYPE_START = 'push_to_start';

    public const TYPE_UPDATE = 'update';

    public const CREW_TRIP = 'CrewTrip';

    public const EDITING_BOARD = 'EditingBoard';

    public const SHOOT = 'Shoot';

    public const ACTIVITIES = [self::CREW_TRIP, self::EDITING_BOARD, self::SHOOT];

    protected $fillable = ['user_id', 'type', 'activity', 'token', 'token_hash', 'activity_id', 'shoot_id', 'environment'];

    protected $casts = ['ended_at' => 'datetime', 'last_sent_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
