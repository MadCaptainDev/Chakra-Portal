<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The studio-authored "month in one paragraph" text on a client's monthly
 * report. Not derived from Instagram -- a human writes it, one row per
 * client per calendar month so an old report's note is never silently
 * overwritten by whatever the current month's textarea holds.
 */
class MonthlyReportNote extends Model
{
    protected $fillable = [
        'client_id',
        'month',
        'note',
        'whatsapp_sent_at',
        'updated_by_id',
    ];

    protected $casts = [
        'month' => 'date',
        'whatsapp_sent_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
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
