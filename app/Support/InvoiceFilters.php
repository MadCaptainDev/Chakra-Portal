<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Every filter on the invoice list, parsed once.
 *
 * The list, its totals and the CSV export all go through query(), so "what
 * you see", "what it adds up to" and "what you download" are one set of
 * invoices by construction -- the same promise the old month sum made,
 * kept now that there is more than a month to filter by.
 *
 * Period: a month (the default, with ‹ ›), a date range, or all time.
 * Picking a client with no period chosen means all time -- "show me Zira's
 * invoices" is a question about Zira, not about this month.
 */
class InvoiceFilters
{
    public const PERIOD_MONTH = 'month';

    public const PERIOD_RANGE = 'range';

    public const PERIOD_ALL = 'all';

    public const STATUSES = [
        '' => 'All',
        'pending_approval' => 'Pending Approval',
        'unpaid' => 'Unpaid',
        'partial' => 'Partially Paid',
        'overdue' => 'Overdue',
        'paid' => 'Paid',
    ];

    public const TYPES = [
        '' => 'All work',
        'production' => 'Production',
        'studio' => 'App Studio',
        'amc' => 'AMC only',
        'development' => 'Development only',
    ];

    public const SORTS = [
        'newest' => 'Newest first',
        'oldest' => 'Oldest first',
        'amount_desc' => 'Amount: high to low',
        'amount_asc' => 'Amount: low to high',
        'due' => 'Due date: soonest',
        'client' => 'Client A–Z',
    ];

    public function __construct(
        public readonly string $search,
        public readonly string $status,
        public readonly string $type,
        public readonly ?int $clientId,
        public readonly string $period,
        public readonly Carbon $month,
        public readonly ?Carbon $from,
        public readonly ?Carbon $to,
        public readonly ?float $min,
        public readonly ?float $max,
        public readonly string $sort,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $clientId = (int) $request->query('client') ?: null;
        $period = (string) $request->query('period');

        if (! in_array($period, [self::PERIOD_MONTH, self::PERIOD_RANGE, self::PERIOD_ALL], true)) {
            $period = $clientId && ! $request->filled('month') ? self::PERIOD_ALL : self::PERIOD_MONTH;
        }

        $from = self::date($request->query('from'));
        $to = self::date($request->query('to'));

        if ($period === self::PERIOD_RANGE && ! $from && ! $to) {
            $period = self::PERIOD_MONTH;
        }

        if ($from && $to && $from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $status = (string) $request->query('status');
        $type = (string) $request->query('type');
        $sort = (string) $request->query('sort');

        return new self(
            search: trim((string) $request->query('search')),
            status: array_key_exists($status, self::STATUSES) ? $status : '',
            type: array_key_exists($type, self::TYPES) ? $type : '',
            clientId: $clientId,
            period: $period,
            month: self::month($request->query('month')),
            from: $from,
            to: $to,
            min: self::amount($request->query('min')),
            max: self::amount($request->query('max')),
            sort: array_key_exists($sort, self::SORTS) ? $sort : 'newest',
        );
    }

    /**
     * The invoices these filters select, unsorted -- sums and exports use it
     * as is, the list adds sorted().
     */
    public function query(): Builder
    {
        return Invoice::query()
            ->when($this->period === self::PERIOD_MONTH, fn (Builder $q) => $q
                ->whereDate('invoice_date', '>=', $this->month->copy()->startOfMonth()->toDateString())
                ->whereDate('invoice_date', '<=', $this->month->copy()->endOfMonth()->toDateString()))
            ->when($this->period === self::PERIOD_RANGE && $this->from, fn (Builder $q) => $q
                ->whereDate('invoice_date', '>=', $this->from->toDateString()))
            ->when($this->period === self::PERIOD_RANGE && $this->to, fn (Builder $q) => $q
                ->whereDate('invoice_date', '<=', $this->to->toDateString()))
            ->when($this->clientId, fn (Builder $q) => $q->where('client_id', $this->clientId))
            ->when($this->search !== '', function (Builder $q) {
                $q->where(function (Builder $inner) {
                    $inner->where('invoice_number', 'like', "%{$this->search}%")
                        ->orWhereHas('client', fn (Builder $c) => $c->where('name', 'like', "%{$this->search}%"));
                });
            })
            ->when($this->status === 'overdue', fn (Builder $q) => $q->overdue())
            ->when($this->status === 'partial', fn (Builder $q) => $q->partiallyPaid())
            // "overdue" and "partial" are derived, not stored statuses.
            ->when($this->status !== '' && ! in_array($this->status, ['overdue', 'partial'], true),
                fn (Builder $q) => $q->where('status', $this->status))
            // Chakra Production vs Chakra App Studio, per saas_product_id;
            // amc/development narrow to one kind of App Studio invoice.
            ->when($this->type === 'studio', fn (Builder $q) => $q->whereNotNull('saas_product_id'))
            ->when($this->type === 'production', fn (Builder $q) => $q->whereNull('saas_product_id'))
            ->when($this->type === 'amc', fn (Builder $q) => $q->where('saas_invoice_type', Invoice::STUDIO_TYPE_AMC))
            ->when($this->type === 'development', fn (Builder $q) => $q->where('saas_invoice_type', Invoice::STUDIO_TYPE_DEVELOPMENT))
            ->when($this->min !== null, fn (Builder $q) => $q->where('total', '>=', $this->min))
            ->when($this->max !== null, fn (Builder $q) => $q->where('total', '<=', $this->max));
    }

    public function sorted(): Builder
    {
        $query = $this->query();

        return match ($this->sort) {
            'oldest' => $query->orderBy('invoice_date')->orderBy('id'),
            'amount_desc' => $query->orderByDesc('total')->orderByDesc('id'),
            'amount_asc' => $query->orderBy('total')->orderBy('id'),
            // Undated invoices last, whichever way the database sorts NULLs.
            'due' => $query->orderByRaw('due_date is null')->orderBy('due_date')->orderBy('id'),
            'client' => $query->orderBy(
                Client::select('name')->whereColumn('clients.id', 'invoices.client_id')->limit(1)
            )->latest('invoice_date'),
            default => $query->latest('invoice_date')->orderByDesc('id'),
        };
    }

    /**
     * Money for exactly the filtered invoices.
     *
     * @return array{count: int, invoiced: float, collected: float, outstanding: float, overdue: float}
     */
    public function summary(): array
    {
        $ids = $this->query()->select('invoices.id');
        $paid = fn (Builder $invoices) => (float) Payment::query()
            ->whereIn('invoice_id', $invoices->select('invoices.id'))
            ->sum('amount');

        $unpaid = $this->query()->where('status', Invoice::STATUS_UNPAID);
        $overdue = (clone $unpaid)->overdue();

        return [
            'count' => $this->query()->count(),
            'invoiced' => (float) $this->query()->sum('total'),
            'collected' => (float) Payment::query()->whereIn('invoice_id', $ids)->sum('amount'),
            'outstanding' => max(0, (float) (clone $unpaid)->sum('total') - $paid(clone $unpaid)),
            'overdue' => max(0, (float) (clone $overdue)->sum('total') - $paid(clone $overdue)),
        ];
    }

    /**
     * This filter set as URL parameters, with $changes applied -- a null
     * change removes that key. Defaults are left out so URLs stay short.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, string>
     */
    public function params(array $changes = []): array
    {
        $params = [
            'search' => $this->search,
            'status' => $this->status,
            'type' => $this->type,
            'client' => $this->clientId ? (string) $this->clientId : '',
            'period' => $this->period,
            'month' => $this->period === self::PERIOD_MONTH ? $this->month->format('Y-m') : '',
            'from' => $this->period === self::PERIOD_RANGE ? (string) $this->from?->toDateString() : '',
            'to' => $this->period === self::PERIOD_RANGE ? (string) $this->to?->toDateString() : '',
            'min' => $this->min !== null ? self::plain($this->min) : '',
            'max' => $this->max !== null ? self::plain($this->max) : '',
            'sort' => $this->sort === 'newest' ? '' : $this->sort,
        ];

        foreach ($changes as $key => $value) {
            $params[$key] = $value === null ? '' : (string) $value;
        }

        // Period-specific keys only make sense with their period.
        if (($params['period'] ?? '') !== self::PERIOD_MONTH) {
            $params['month'] = '';
        }
        if (($params['period'] ?? '') !== self::PERIOD_RANGE) {
            $params['from'] = $params['to'] = '';
        }

        return array_filter($params, fn ($v) => $v !== '');
    }

    /** How many of the "More filters" options are in use -- the button's badge. */
    public function advancedCount(): int
    {
        return count(array_filter([
            $this->period !== self::PERIOD_MONTH,
            $this->type !== '',
            $this->min !== null || $this->max !== null,
            $this->sort !== 'newest',
        ]));
    }

    public function periodLabel(): string
    {
        return match ($this->period) {
            self::PERIOD_ALL => 'All time',
            self::PERIOD_RANGE => match (true) {
                $this->from && $this->to => $this->from->format('j M Y').' – '.$this->to->format('j M Y'),
                (bool) $this->from => 'From '.$this->from->format('j M Y'),
                default => 'Up to '.$this->to->format('j M Y'),
            },
            default => $this->month->format('F Y'),
        };
    }

    public function isFiltered(): bool
    {
        return $this->search !== '' || $this->status !== '' || $this->type !== '' || $this->clientId
            || $this->min !== null || $this->max !== null;
    }

    private static function month(mixed $value): Carbon
    {
        if (! is_string($value) || $value === '') {
            return now()->startOfMonth();
        }

        try {
            return Carbon::parse(strlen($value) === 7 ? $value.'-01' : $value)->startOfMonth();
        } catch (Throwable) {
            return now()->startOfMonth();
        }
    }

    private static function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }
    }

    private static function amount(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $clean = str_replace([',', '₹', ' '], '', (string) $value);

        return is_numeric($clean) && (float) $clean >= 0 ? (float) $clean : null;
    }

    private static function plain(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
