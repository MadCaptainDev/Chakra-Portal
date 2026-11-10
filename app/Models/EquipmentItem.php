<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Support\ImageVariant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'paired_with_id',
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

    /** The camera (or other item) this accessory always travels with. */
    public function pairedWith(): BelongsTo
    {
        return $this->belongsTo(self::class, 'paired_with_id');
    }

    /** Everything that travels with this item -- batteries, chargers, cages. */
    public function accessories(): HasMany
    {
        return $this->hasMany(self::class, 'paired_with_id');
    }

    /**
     * What goes with this item on a shoot, and how many of each -- the FX30's
     * power zoom lens and 256GB card. See App\Support\KitBundle.
     */
    public function bundle(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'equipment_bundle_items', 'parent_item_id', 'child_item_id')
            ->withPivot('quantity')->withTimestamps()->orderBy('name');
    }

    /** The items this one travels with. */
    public function bundledWith(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'equipment_bundle_items', 'child_item_id', 'parent_item_id')
            ->orderBy('name');
    }

    /** The picture as uploaded, or null when it has none. */
    public function imageUrl(): ?string
    {
        return $this->image_path ? ImageVariant::url($this->image_path, 800, 800) : null;
    }

    /** A small square-ish copy for lists and tiles. */
    public function thumbUrl(): ?string
    {
        return $this->image_path ? ImageVariant::url($this->image_path, 200, 200) : null;
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
