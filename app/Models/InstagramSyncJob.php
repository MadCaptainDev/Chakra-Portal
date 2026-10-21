<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One account's turn in a "Sync all Instagram" run (InstagramSyncQueue). */
class InstagramSyncJob extends Model
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    /** The connection is dead; only the client logging in again fixes it. */
    public const RECONNECT = 'reconnect';

    protected $fillable = ['batch', 'social_account_id', 'queued_by_id', 'status', 'message', 'started_at', 'finished_at'];

    protected $casts = ['started_at' => 'datetime', 'finished_at' => 'datetime'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::QUEUED, self::RUNNING], true);
    }
}
