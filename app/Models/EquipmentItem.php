<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of thing the studio owns, and how many of it there are.
 */
class EquipmentItem extends Model
{
    use HasFactory;

    /**
     * Operational condition -- distinct from `is_active`, which means
     * "retired: no longer owned, or never will be again". This is
     * "owned, but is it actually fit to take on a shoot right now".
     */
    public const STATUS_AVAILABLE = 'available';

    public const STATUS_IN_REPAIR = 'in_repair';

    public const STATUS_DAMAGED = 'damaged';

    public const STATUS_LOST = 'lost';

    public const STATUSES = [
        self::STATUS_AVAILABLE => 'Available',
        self::STATUS_IN_REPAIR => 'In Repair',
        self::STATUS_DAMAGED => 'Damaged',
        self::STATUS_LOST => 'Lost',
    ];

    protected $fillable = [
        'name',
        'category_id',
        'identifier',
        'quantity',
        'is_active',
        'status',
        'status_note',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'is_active' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(TaxonomyTerm::class, 'category_id');
    }

    public function kits(): HasMany
    {
        return $this->hasMany(ShootKit::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('name');
    }

    public function categoryLabel(): string
    {
        return $this->category?->name ?? 'Uncategorised';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst(str_replace('_', ' ', (string) $this->status));
    }

    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE;
    }

    /** "Gimbal" or "NP-F970 battery ×12", for a list that has to scan fast. */
    public function label(): string
    {
        return $this->quantity > 1
            ? $this->name.' ×'.$this->quantity
            : $this->name;
    }
}
