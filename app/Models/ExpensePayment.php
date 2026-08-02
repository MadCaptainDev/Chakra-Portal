<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpensePayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'expense_id',
        'period',
        'amount_paid',
        'paid_on',
        'note',
    ];

    protected $casts = [
        'period' => 'date',
        'amount_paid' => 'decimal:2',
        'paid_on' => 'date',
    ];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function isPaid(): bool
    {
        return (float) $this->amount_paid > 0;
    }
}
