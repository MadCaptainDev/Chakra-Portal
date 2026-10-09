<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    use HasFactory;

    /** Posted by the portal when a reel crosses a views milestone (ViewMilestones). */
    public const KIND_MILESTONE = 'milestone';

    protected $fillable = [
        'title',
        'body',
        'kind',
        'link_url',
        'is_active',
        'visible_to_clients',
        'expires_at',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'visible_to_clients' => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Switched on, and not past its end date (milestones run for a week). */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isMilestone(): bool
    {
        return $this->kind === self::KIND_MILESTONE;
    }

    public function hasEnded(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Active AND explicitly opted in for a client audience -- see the
     * migration adding visible_to_clients for why this is never the
     * default.
     */
    public function scopeVisibleToClients(Builder $query): void
    {
        $query->active()->where('visible_to_clients', true);
    }
}
