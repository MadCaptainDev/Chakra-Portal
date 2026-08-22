<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One client-built, Chakra-maintained piece of software -- DJ
 * Thangamaaligai's ERP is the first. See the migration for the full
 * reasoning behind token_hash and is_suspended.
 */
class SaasProduct extends Model
{
    public const PREFIX = 'saas_';

    private const RANDOM_LENGTH = 40;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_OVERDUE = 'overdue';

    public const STATUS_SUSPENDED = 'suspended';

    /*
     * token_hash, suspended_at and suspended_by_id are not fillable: each is
     * derived or stamped by a specific method below (issueToken(),
     * suspend()/reinstate()), never posted directly from a form.
     */
    protected $fillable = [
        'client_id',
        'name',
        'backup_retention_count',
        'amc_paid_until',
        'recurring_invoice_id',
    ];

    protected $casts = [
        'amc_paid_until' => 'date',
        'is_suspended' => 'boolean',
        'suspended_at' => 'datetime',
        'backup_retention_count' => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function backups(): HasMany
    {
        return $this->hasMany(SaasBackup::class);
    }

    public function suspendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suspended_by_id');
    }

    public function recurringInvoice(): BelongsTo
    {
        return $this->belongsTo(RecurringInvoice::class);
    }

    /**
     * Mint a token for this product, returning the one and only copy of the
     * plaintext -- same shape as McpToken::issue(). Overwrites any previous
     * token: one active key per product is enough for this use case (unlike
     * MCP's per-user multiple tokens), so issuing a new one silently retires
     * the old rather than requiring an explicit revoke step first.
     */
    public function issueToken(): string
    {
        $plain = self::PREFIX.Str::random(self::RANDOM_LENGTH);
        $this->forceFill(['token_hash' => self::hash($plain)])->save();

        return $plain;
    }

    /**
     * Whichever product this token belongs to, or null. Looked up by hash,
     * never by comparing plaintext -- same reasoning as McpToken::resolve().
     */
    public static function resolveToken(?string $plain): ?self
    {
        if (! is_string($plain) || ! str_starts_with($plain, self::PREFIX)) {
            return null;
        }

        return self::where('token_hash', self::hash($plain))->first();
    }

    private static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * suspended overrides everything else regardless of payment status --
     * it is the one explicit admin lever. overdue/active are computed fresh
     * from amc_paid_until on every call, never cached, so there is nothing
     * to keep in sync when a payment lands or a day passes.
     */
    public function status(): string
    {
        if ($this->is_suspended) {
            return self::STATUS_SUSPENDED;
        }

        if ($this->amc_paid_until !== null && $this->amc_paid_until->isPast()) {
            return self::STATUS_OVERDUE;
        }

        return self::STATUS_ACTIVE;
    }

    public function suspend(User $by): void
    {
        $this->forceFill([
            'is_suspended' => true,
            'suspended_at' => now(),
            'suspended_by_id' => $by->id,
        ])->save();
    }

    public function reinstate(): void
    {
        $this->forceFill([
            'is_suspended' => false,
            'suspended_at' => null,
            'suspended_by_id' => null,
        ])->save();
    }

    /**
     * Called when an invoice billing this product's AMC is paid in full
     * (see Invoice::recalculateStatus()). Extends from whichever is later
     * of today and the current paid-until date, not just "+1 year from
     * today" -- a renewal paid early adds to the remaining term instead of
     * shortening it, and a lapsed one starts the new year from today
     * rather than backdating it.
     */
    public function extendAmc(): void
    {
        $base = $this->amc_paid_until !== null && $this->amc_paid_until->isFuture()
            ? $this->amc_paid_until
            : today();

        $this->forceFill(['amc_paid_until' => $base->copy()->addYear()])->save();
    }
}
