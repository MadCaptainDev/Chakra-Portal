<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'body',
        'is_active',
        'visible_to_clients',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'visible_to_clients' => 'boolean',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
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
