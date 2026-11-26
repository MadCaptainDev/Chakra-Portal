<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An editor's "cancel this video" or "move it in the planner", waiting for an admin. */
class VideoRequest extends Model
{
    public const KINDS = ['cancel' => 'Cancel video', 'move' => 'Move planner date', 'status' => 'Change planner status'];

    protected $guarded = [];

    protected $casts = ['payload' => 'array', 'decided_at' => 'datetime'];

    public function todo(): BelongsTo
    {
        return $this->belongsTo(Todo::class);
    }

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }

    /** What it asks, in a line. */
    public function summary(): string
    {
        $p = $this->payload ?? [];

        return match ($this->kind) {
            'cancel' => 'Cancel'.(filled($p['note'] ?? null) ? ': '.$p['note'] : ''),
            'move' => 'Move date '.($p['from'] ?? 'none').' → '.($p['to'] ?? 'none'),
            'status' => 'Set status to '.($p['status'] ?? '?'),
            default => $this->kind,
        };
    }
}
