<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A quotation sent to a prospective or existing client before any work is
 * booked. Accepted quotations can be turned into a real Invoice with
 * convertToInvoice(), carrying the line items across so nothing is
 * retyped -- that is also the one place a SaaS product actually gets
 * picked (see Invoice::saas_product_id): is_app_studio here is just a
 * label for which side of Chakra the quote is for, not a link to a real
 * product yet.
 */
class Quotation extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    /**
     * The Meta template QuotationController::sendWhatsapp() sends. See
     * Invoice::WHATSAPP_TEMPLATE's own doc block for the "_v1"/"_v2"
     * naming convention -- must exist and be Meta-approved before the
     * "Send via WhatsApp" button on a quotation's show page can send
     * (run `app:seed-quotation-ready-template` once to submit it).
     */
    public const WHATSAPP_TEMPLATE = 'quotation_ready_v1';

    protected $fillable = [
        'quotation_number',
        'client_id',
        'is_app_studio',
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
        'whatsapp_sent_at',
        'created_by',
    ];

    protected $casts = [
        'is_app_studio' => 'boolean',
        'quotation_date' => 'date',
        'valid_until' => 'date',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'whatsapp_sent_at' => 'datetime',
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

    /**
     * Every "Send via WhatsApp" attempt against this quotation, latest
     * first -- see WhatsappSendLog's own doc block.
     */
    public function whatsappLogs(): MorphMany
    {
        return $this->morphMany(WhatsappSendLog::class, 'loggable')->latest();
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
     * A quotation can be sent (or re-sent) over WhatsApp at any time, unlike
     * an invoice, which withholds it until approved -- a quotation has no
     * pending-approval state and its number is assigned the moment it is
     * created, so there is nothing left to finalize first.
     */
    public function isSendableViaWhatsapp(): bool
    {
        return true;
    }

    /**
     * The token this quotation's no-login PDF link is reached by, minting
     * one on first use. Same convention as Invoice::ensurePublicToken().
     */
    public function ensurePublicToken(): string
    {
        if ($this->public_token === null) {
            $this->forceFill(['public_token' => Str::random(48)])->save();
        }

        return $this->public_token;
    }

    public function publicUrl(): string
    {
        return route('quotations.public-pdf', $this->ensurePublicToken());
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
