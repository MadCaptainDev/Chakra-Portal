<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One morning or evening round of the Comment & Message Checker -- see App\Services\CheckRounds. */
class CheckRound extends Model
{
    protected $fillable = ['routine_id', 'round', 'on_date', 'started_by', 'started_at', 'auto_started', 'ended_at', 'checks', 'replies'];

    protected $casts = [
        'on_date' => 'date',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'auto_started' => 'boolean',
        'checks' => 'integer',
        'replies' => 'integer',
    ];

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function minutes(): ?int
    {
        return $this->ended_at ? (int) $this->started_at->diffInMinutes($this->ended_at) : null;
    }
}
