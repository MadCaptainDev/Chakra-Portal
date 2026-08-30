<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Invoice extends Model
{
    use HasFactory;

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_PAID = 'paid';

    /**
     * The Meta template InvoiceController::sendWhatsapp() sends.
     *
     * Named "_v2" because the original "invoice_ready" got stuck mid-delete
     * on Meta's side (a REJECTED submission cleared to make its name
     * resubmittable, but Meta's own docs "try again in under a minute"
     * ran well past ten) -- Meta's own error suggests exactly this escape
     * hatch ("consider creating a new message template"), so this is that,
     * not a versioning convention to keep bumping.
     */
    public const WHATSAPP_TEMPLATE = 'invoice_ready_v2';

    /**
     * The two things a Chakra App Studio invoice can be -- not every one is
     * AMC. A one-off build/dev-work invoice is App Studio income too, but
     * paying it must never extend a product's AMC term the way paying an
     * actual AMC invoice does (see recalculateStatus()). Meaningless, and
     * left null, on any invoice with no saas_product_id at all.
     */
    public const STUDIO_TYPE_AMC = 'amc';

    public const STUDIO_TYPE_DEVELOPMENT = 'development';

    public const STUDIO_TYPES = [
        self::STUDIO_TYPE_AMC => 'AMC',
        self::STUDIO_TYPE_DEVELOPMENT => 'Development',
    ];

    /**
     * Tolerance when comparing a float payment sum against the decimal:2
     * total: split payments land a hair off (9999.999999999998) and must
     * still settle the invoice. Same convention as Expense/ExpenseLedger.
     */
    public const AMOUNT_EPSILON = 0.001;

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
        'whatsapp_sent_at',
        'public_token',
        'created_by',
        'recurring_invoice_id',
        'saas_product_id',
        'saas_invoice_type',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'approved_at' => 'datetime',
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

    public function recurringInvoice(): BelongsTo
    {
        return $this->belongsTo(RecurringInvoice::class);
    }

    /**
     * The SaasProduct this invoice bills App Studio work for -- AMC or a
     * one-off development invoice, see saas_invoice_type -- or null for
     * ordinary Chakra Production work.
     */
    public function saasProduct(): BelongsTo
    {
        return $this->belongsTo(SaasProduct::class);
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

    /**
     * Unpaid invoices carrying at least one payment. "unpaid" already means
     * paid < total, so an existence check is exact - no SUM needed.
     */
    public function scopePartiallyPaid(Builder $query): void
    {
        $query->where('status', self::STATUS_UNPAID)->whereHas('payments');
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
        return (float) ($this->relationLoaded('payments')
            ? $this->payments->sum('amount')
            : $this->payments()->sum('amount'));
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
     * Whether the "Send via WhatsApp" button belongs on this invoice.
     *
     * Recurring-generated only, on purpose: a manually created invoice
     * usually gets handed over some other way (in person, email, a client
     * portal) that was already the plan before this feature existed, so
     * offering the button there would be a second, uncoordinated channel for
     * the same invoice rather than the one this was actually asked for --
     * "once I approve a recurring invoice, I can send it to the client".
     */
    public function isSendableViaWhatsapp(): bool
    {
        return ! $this->isPendingApproval()
            && $this->recurring_invoice_id !== null
            && filled($this->client?->phone);
    }

    /**
     * The token this invoice's no-login PDF link is reached by, minting one
     * on first use. Never rotated afterwards -- unlike ClientBrief's
     * public_token, there is no "close this link" need for a bare PDF that
     * carries no write access, so one token for the life of the invoice is
     * simpler and just as safe.
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
        return route('invoices.public-pdf', $this->ensurePublicToken());
    }

    /**
     * Something has been paid, but not the whole invoice.
     */
    public function isPartiallyPaid(): bool
    {
        return $this->status === self::STATUS_UNPAID && $this->paidTotal() > 0;
    }

    /**
     * State for badges and filters, which is richer than the stored status:
     * "overdue" and "partial" are both derived, never persisted. Overdue
     * wins - a part-paid invoice past its due date still needs chasing, and
     * the list prints the balance alongside so nothing is lost.
     */
    public function displayStatus(): string
    {
        return match (true) {
            $this->isOverdue() => 'overdue',
            $this->isPartiallyPaid() => 'partial',
            default => (string) $this->status,
        };
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
     *
     * An invoice that just became fully paid, and specifically bills a
     * SaasProduct's AMC (not a one-off App Studio development invoice --
     * see STUDIO_TYPE_AMC), extends that product's paid-until date here --
     * this is the one place every path that can settle an invoice (a new
     * payment, an edited one) already funnels through, via
     * PaymentObserver. Only fires on the unpaid -> paid transition, not
     * e.g. re-saving an already-paid invoice, so it can never be applied
     * twice for one invoice.
     */
    public function recalculateStatus(): void
    {
        if ($this->isPendingApproval()) {
            return;
        }

        $wasPaid = $this->status === self::STATUS_PAID;

        $this->status = $this->paidTotal() + self::AMOUNT_EPSILON >= (float) $this->total
            ? self::STATUS_PAID
            : self::STATUS_UNPAID;

        $this->save();

        if (! $wasPaid && $this->status === self::STATUS_PAID
            && $this->saas_product_id && $this->saas_invoice_type === self::STUDIO_TYPE_AMC) {
            $this->saasProduct?->extendAmc();
        }
    }
}
