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

    protected $fillable = [
        'name',
        'category_id',
        'identifier',
        'quantity',
        'is_active',
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

    /** "Gimbal" or "NP-F970 battery ×12", for a list that has to scan fast. */
    public function label(): string
    {
        return $this->quantity > 1
            ? $this->name.' ×'.$this->quantity
            : $this->name;
    }
}
