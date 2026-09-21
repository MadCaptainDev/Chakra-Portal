<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cost the studio carried on a client's behalf, and whether it has come
 * back yet.
 *
 * Distinct from App\Models\Expense, which is what the business spends on
 * itself and never sees again. The difference that matters is the last
 * column: an advance is a debt owed TO the studio, so it belongs beside the
 * client it was spent on, not in the monthly outflow total.
 */
class ClientAdvance extends Model
{
    /*
     * recovered_at and recovered_note are absent on purpose. Saying the money
     * came back is a claim about the world, made by one action
     * (ClientAdvanceController::markRecovered) -- not something an edit form
     * post should be able to assert in passing. Same reason
     * ShootCrew::confirmed_at and Expense's state columns stay out.
     */
    protected $fillable = [
        'client_id',
        'category_id',
        'name',
        'payee',
        'amount',
        'billable_amount',
        'spent_on',
        'receipt_path',
        'notes',
        'created_by_id',
    ];

    protected $casts = [
        'spent_on' => 'date',
        'recovered_at' => 'datetime',
        'amount' => 'decimal:2',
        'billable_amount' => 'decimal:2',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Named categoryTerm() rather than category() for the reason
     * Script::platformTerm() documents: a same-named column would shadow the
     * relation.
     */
    public function categoryTerm(): BelongsTo
    {
        return $this->belongsTo(TaxonomyTerm::class, 'category_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * What the client is charged.
     *
     * Falls back to what was paid, which is the common case: most advances
     * are passed straight through at cost.
     */
    public function billable(): float
    {
        return (float) ($this->billable_amount ?? $this->amount);
    }

    /** What the studio keeps on this one, if anything. */
    public function margin(): float
    {
        return $this->billable() - (float) $this->amount;
    }

    public function isRecovered(): bool
    {
        return $this->recovered_at !== null;
    }

    /** Still owed to the studio. */
    public function scopeOutstanding(Builder $query): void
    {
        $query->whereNull('recovered_at');
    }

    public function scopeRecovered(Builder $query): void
    {
        $query->whereNotNull('recovered_at');
    }

    public function categoryLabel(): string
    {
        return $this->categoryTerm?->name ?? 'Uncategorised';
    }
}
