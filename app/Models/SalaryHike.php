<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One raise (or cut) applied to an employee's monthly salary -- see the
 * migration's own doc block for why this is a history table rather than
 * just overwriting expenses.amount.
 */
class SalaryHike extends Model
{
    use HasFactory;

    protected $fillable = [
        'expense_id',
        'previous_amount',
        'new_amount',
        'effective_on',
        'reason',
        'created_by',
    ];

    protected $casts = [
        'previous_amount' => 'decimal:2',
        'new_amount' => 'decimal:2',
        'effective_on' => 'date',
    ];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Positive for a raise, negative for a cut. */
    public function delta(): float
    {
        return (float) $this->new_amount - (float) $this->previous_amount;
    }
}
