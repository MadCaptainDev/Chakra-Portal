<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Invoice extends Model
{
    use HasFactory;

    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_UNPAID = 'unpaid';
    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'invoice_number',
        'client_id',
        'invoice_date',
        'due_date',
        'intro_text',
        'discount_label',
        'discount_amount',
        'subtotal',
        'total',
        'status',
        'approved_at',
        'created_by',
        'recurring_invoice_id',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'approved_at' => 'datetime',
        'discount_amount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function scopePendingApproval(Builder $query): void
    {
        $query->where('status', self::STATUS_PENDING_APPROVAL);
    }

    public function scopeUnpaid(Builder $query): void
    {
        $query->where('status', self::STATUS_UNPAID);
    }

    public function scopePaid(Builder $query): void
    {
        $query->where('status', self::STATUS_PAID);
    }

    public function scopeOverdue(Builder $query): void
    {
        $query->where('status', self::STATUS_UNPAID)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString());
    }

    /**
     * Generate the next sequential invoice number for the given prefix,
     * e.g. "CP-0001". Wrapped in a transaction by the caller to avoid races.
     */
    public static function nextInvoiceNumber(string $prefix): string
    {
        $lastNumber = static::query()
            ->where('invoice_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->get()
            ->map(fn (self $invoice) => (int) substr($invoice->invoice_number, strlen($prefix)))
            ->max() ?? 0;

        return $prefix.str_pad((string) ($lastNumber + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Recalculate subtotal/total from the currently loaded items and discount.
     */
    public function recalculateTotals(): void
    {
        $subtotal = $this->items->sum('line_total');
        $discount = $this->discount_amount ?? 0;

        $this->subtotal = $subtotal;
        $this->total = $subtotal - $discount;
    }

    /**
     * Total amount paid so far.
     */
    public function paidTotal(): float
    {
        return (float) $this->payments->sum('amount');
    }

    /**
     * Remaining amount owed.
     */
    public function balanceDue(): float
    {
        return max(0, (float) $this->total - $this->paidTotal());
    }

    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_UNPAID
            && $this->due_date !== null
            && $this->due_date->isPast();
    }

    public function isPendingApproval(): bool
    {
        return $this->status === self::STATUS_PENDING_APPROVAL;
    }

    /**
     * Approve a pending-approval invoice: assign it the next sequential
     * invoice number and move it to "unpaid". Wrapped in a transaction so
     * the nextInvoiceNumber() lockForUpdate actually holds against
     * concurrent approvals.
     */
    public function approve(): void
    {
        DB::transaction(function () {
            $prefix = CompanySetting::current()->invoice_prefix;

            $this->invoice_number = self::nextInvoiceNumber($prefix);
            $this->status = self::STATUS_UNPAID;
            $this->approved_at = now();
            $this->save();
        });
    }

    /**
     * Recompute paid/unpaid status from recorded payments. Never touches
     * a pending-approval invoice - approval is a separate, explicit step.
     */
    public function recalculateStatus(): void
    {
        if ($this->isPendingApproval()) {
            return;
        }

        $this->status = $this->paidTotal() >= (float) $this->total
            ? self::STATUS_PAID
            : self::STATUS_UNPAID;

        $this->save();
    }
}
