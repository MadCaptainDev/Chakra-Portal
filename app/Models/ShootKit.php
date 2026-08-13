<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the checklist: this item, this many, going on this shoot.
 */
class ShootKit extends Model
{
    protected $table = 'shoot_kit';

    public const CONDITION_DAMAGED = 'damaged';

    public const CONDITION_MISSING = 'missing';

    public const CONDITIONS = [
        self::CONDITION_DAMAGED => 'Damaged',
        self::CONDITION_MISSING => 'Missing',
    ];

    /*
     * The four custody columns are deliberately absent from $fillable. They are
     * stamped by the check-out and check-in actions, which know who is asking --
     * accepting them from a request would let a form post record that somebody
     * else packed the van, which is the one fact this table exists to hold.
     */
    protected $fillable = [
        'shoot_id',
        'equipment_item_id',
        'quantity',
        'condition',
        'condition_note',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'returned_quantity' => 'integer',
        'checked_out_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    /** How many of this line never came back. */
    public function shortfall(): int
    {
        if ($this->returned_at === null) {
            return 0;
        }

        return max(0, $this->quantity - ($this->returned_quantity ?? $this->quantity));
    }

    public function shoot(): BelongsTo
    {
        return $this->belongsTo(Shoot::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(EquipmentItem::class, 'equipment_item_id');
    }

    public function checkedOutBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_out_by_id');
    }

    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by_id');
    }

    public function isCheckedOut(): bool
    {
        return $this->checked_out_at !== null && $this->returned_at === null;
    }

    public function isReturned(): bool
    {
        return $this->returned_at !== null;
    }

    public function conditionLabel(): ?string
    {
        return $this->condition ? (self::CONDITIONS[$this->condition] ?? ucfirst($this->condition)) : null;
    }
}
