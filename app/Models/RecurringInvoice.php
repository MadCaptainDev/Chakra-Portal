<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecurringInvoice extends Model
{
    use HasFactory;

    public const FREQUENCY_WEEKLY = 'weekly';
    public const FREQUENCY_MONTHLY = 'monthly';
    public const FREQUENCY_QUARTERLY = 'quarterly';
    public const FREQUENCY_YEARLY = 'yearly';

    public const FREQUENCIES = [
        self::FREQUENCY_WEEKLY => 'Weekly',
        self::FREQUENCY_MONTHLY => 'Monthly',
        self::FREQUENCY_QUARTERLY => 'Quarterly',
        self::FREQUENCY_YEARLY => 'Yearly',
    ];

    protected $fillable = [
        'client_id',
        'saas_product_id',
        'label',
        'intro_text',
        'discount_label',
        'discount_amount',
        'frequency',
        'day_of_month',
        'due_days',
        'next_run_on',
        'last_generated_on',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'discount_amount' => 'decimal:2',
        'next_run_on' => 'date',
        'last_generated_on' => 'date',
        'is_active' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Set only for the one schedule that bills a SaasProduct's AMC --
     * see RecurringInvoiceGenerator, which carries this onto every
     * invoice it generates from this schedule.
     */
    public function saasProduct(): BelongsTo
    {
        return $this->belongsTo(SaasProduct::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RecurringInvoiceItem::class)->orderBy('sort_order');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeDue(Builder $query): void
    {
        $query->where('is_active', true)->whereDate('next_run_on', '<=', now()->toDateString());
    }

    /**
     * Compute the next occurrence after the given date, honouring the
     * configured day_of_month and clamping to the last day of shorter
     * months (e.g. a schedule anchored on the 31st bills the 28th/29th
     * in February and returns to the 31st once the month allows it).
     */
    public function nextRunAfter(Carbon $date): Carbon
    {
        $months = match ($this->frequency) {
            self::FREQUENCY_WEEKLY => null,
            self::FREQUENCY_MONTHLY => 1,
            self::FREQUENCY_QUARTERLY => 3,
            self::FREQUENCY_YEARLY => 12,
            default => 1,
        };

        if ($months === null) {
            return $date->copy()->addWeek();
        }

        $next = $date->copy()->addMonthsNoOverflow($months);

        if ($this->day_of_month) {
            $next->day(min($this->day_of_month, $next->daysInMonth));
        }

        return $next;
    }
}
