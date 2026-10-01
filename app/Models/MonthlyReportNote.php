<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

/**
 * The studio-authored "month in one paragraph" text on a client's monthly
 * report. Not derived from Instagram -- a human writes it, one row per
 * client per calendar month so an old report's note is never silently
 * overwritten by whatever the current month's textarea holds.
 */
class MonthlyReportNote extends Model
{
    /** Submitted by SeedMonthlyReportReadyTemplate, sent by NotifyReportsReady. */
    public const WHATSAPP_TEMPLATE = 'monthly_report_ready';

    /**
     * "Send via WhatsApp" on the report screen, to anyone outside the
     * 24-hour window: a link to the PDF (/r/{token}) in an approved template.
     * Submitted by SeedMonthlyReportLinkTemplate.
     */
    public const WHATSAPP_LINK_TEMPLATE = 'monthly_report_link_v1';

    protected $fillable = [
        'client_id',
        'month',
        'note',
        'whatsapp_sent_at',
        'ready_notified_at',
        'updated_by_id',
    ];

    protected $casts = [
        'month' => 'date',
        'whatsapp_sent_at' => 'datetime',
        'shared_sections' => 'array',
        'ready_notified_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    /** Every WhatsApp send of this report, newest first, with its delivery status. */
    public function whatsappLogs(): MorphMany
    {
        return $this->morphMany(WhatsappSendLog::class, 'loggable')->latest();
    }

    /**
     * The unguessable token behind /r/{token}, made the first time the
     * report is shared. Not fillable: nobody posts one.
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
        return route('reports.public-pdf', $this->ensurePublicToken());
    }

    /**
     * The row for this client and calendar month, unsaved if it doesn't
     * exist yet -- the report screen can read `->note` (null-safe) without
     * writing a row for every month anybody merely opens, only when they
     * actually save one.
     *
     * NOT firstOrNew(['month' => $bareDateString]): Eloquent's plain
     * 'date' cast writes "2026-06-01 00:00:00" on save (the cast only
     * normalises what is READ back, not the format used to WRITE), so a
     * bare "2026-06-01" passed into a raw where-array match never equals
     * what is actually stored -- firstOrNew() would find nothing, ever,
     * and silently insert a fresh duplicate row every time this is called
     * for a month that already has one. Same bug class already documented
     * and fixed elsewhere in this codebase (TimesheetEntry::scopeForMonth,
     * SocialInsight::scopeBetween); whereDate() compares against the DATE
     * portion in SQL, which is correct regardless of the stored string's
     * time suffix.
     */
    public static function forClientAndMonth(Client $client, Carbon $month): self
    {
        $monthStart = $month->copy()->startOfMonth()->toDateString();

        return static::where('client_id', $client->id)
            ->whereDate('month', $monthStart)
            ->first() ?? new static(['client_id' => $client->id, 'month' => $monthStart]);
    }
}
