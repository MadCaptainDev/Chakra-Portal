<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A quotation sent to a prospective or existing client before any work is
 * booked -- internal-only for now, no public/client-facing link (see
 * QuotationController). Accepted quotations can be turned into a real
 * Invoice with convertToInvoice(), carrying the line items across so
 * nothing is retyped.
 */
class Quotation extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'quotation_number',
        'client_id',
        'quotation_date',
        'valid_until',
        'intro_text',
        'notes',
        'discount_label',
        'discount_amount',
        'subtotal',
        'total',
        'status',
        'accepted_at',
        'rejected_at',
        'converted_invoice_id',
        'created_by',
    ];

    protected $casts = [
        'quotation_date' => 'date',
        'valid_until' => 'date',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
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
        return $this->hasMany(QuotationItem::class)->orderBy('sort_order');
    }

    public function convertedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'converted_invoice_id');
    }

    public function scopeDraft(Builder $query): void
    {
        $query->where('status', self::STATUS_DRAFT);
    }

    public function scopeAccepted(Builder $query): void
    {
        $query->where('status', self::STATUS_ACCEPTED);
    }

    public function scopeRejected(Builder $query): void
    {
        $query->where('status', self::STATUS_REJECTED);
    }

    /**
     * Still awaiting a decision, and past the date it promised the price
     * until. Derived, never stored -- same convention as
     * Invoice::isOverdue()/displayStatus().
     */
    public function isExpired(): bool
    {
        return $this->status === self::STATUS_DRAFT
            && $this->valid_until !== null
            && $this->valid_until->isPast();
    }

    public function isConverted(): bool
    {
        return $this->converted_invoice_id !== null;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function canAccept(): bool
    {
        return $this->isDraft();
    }

    public function canReject(): bool
    {
        return $this->isDraft();
    }

    public function canConvert(): bool
    {
        return $this->status === self::STATUS_ACCEPTED && ! $this->isConverted();
    }

    /**
     * State for badges and filters, richer than the stored status: "expired"
     * and "converted" are both derived. Converted wins over accepted -- once
     * the invoice exists that is the more useful fact to show.
     */
    public function displayStatus(): string
    {
        return match (true) {
            $this->isConverted() => 'converted',
            $this->isExpired() => 'expired',
            default => (string) $this->status,
        };
    }

    /**
     * Generate the next sequential quotation number for the given prefix,
     * e.g. "QT-0001". Wrapped in a transaction by the caller to avoid races.
     */
    public static function nextQuotationNumber(string $prefix): string
    {
        $lastNumber = static::query()
            ->where('quotation_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->get()
            ->map(fn (self $quotation) => (int) substr($quotation->quotation_number, strlen($prefix)))
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

    public function accept(): void
    {
        $this->update([
            'status' => self::STATUS_ACCEPTED,
            'accepted_at' => now(),
            'rejected_at' => null,
        ]);
    }

    public function reject(): void
    {
        $this->update([
            'status' => self::STATUS_REJECTED,
            'rejected_at' => now(),
            'accepted_at' => null,
        ]);
    }

    /**
     * Turn this accepted quotation into a real Invoice, copying its items
     * across so nothing is retyped. The invoice starts unpaid and dated
     * today -- the quotation's own quotation_date is history, not when the
     * work is actually being billed.
     */
    public function convertToInvoice(int $userId): Invoice
    {
        $this->loadMissing('items');

        return DB::transaction(function () use ($userId) {
            $settings = CompanySetting::current();

            $invoice = Invoice::create([
                'invoice_number' => Invoice::nextInvoiceNumber($settings->invoice_prefix),
                'client_id' => $this->client_id,
                'invoice_date' => now()->format('Y-m-d'),
                'intro_text' => $this->intro_text,
                'discount_label' => $this->discount_label,
                'discount_amount' => $this->discount_amount,
                'status' => Invoice::STATUS_UNPAID,
                'created_by' => $userId,
            ]);

            foreach ($this->items as $item) {
                $invoice->items()->create([
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'line_total' => $item->line_total,
                    'sort_order' => $item->sort_order,
                ]);
            }

            $invoice->load('items');
            $invoice->recalculateTotals();
            $invoice->save();

            $this->update(['converted_invoice_id' => $invoice->id]);

            return $invoice;
        });
    }
}
