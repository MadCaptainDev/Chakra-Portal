<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One comment on a proposal. A null user_id is a client writing through the
 * public link; a set one is staff. A null section_key is general feedback on
 * the whole document.
 */
class ProposalComment extends Model
{
    protected $fillable = [
        'proposal_id',
        'section_key',
        'parent_id',
        'user_id',
        'author_name',
        'author_email',
        'body',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->oldest();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    public function scopeTopLevel(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    public function isFromStaff(): bool
    {
        return $this->user_id !== null;
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    /** Plain text only, same as ScriptComment -- a note, not markup. */
    public function setBodyAttribute(?string $value): void
    {
        $this->attributes['body'] = trim(strip_tags((string) $value));
    }
}
