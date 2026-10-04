<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One month of paid-ads results for one client -- the stored form of the JSON
 * report described on AdReportImporter.
 *
 * `data` is deliberately never edited in place: a corrected report is
 * imported again and replaces the old one for that client and month. The page
 * a client reads is therefore always exactly something the producer wrote, not
 * a hand-edited hybrid.
 */
class AdReport extends Model
{
    /**
     * The Meta template that carries the link outside WhatsApp's 24-hour
     * window: its button is https://.../results/{{1}}, filled with the token.
     * Must be approved before that path works -- see
     * app:seed-ad-report-ready-template.
     */
    public const WHATSAPP_TEMPLATE = 'ad_report_ready_v1';

    /*
     * public_token, token_issued_at and first_viewed_at are not fillable: the
     * link is made and switched off by issuePublicToken() and
     * revokePublicToken(), and "the client has seen it" is only ever stamped
     * by markViewed(). Same reasoning as Proposal.
     */
    protected $fillable = [
        'client_id',
        'platform',
        'period_start',
        'period_end',
        'title',
        'data',
        'created_by_id',
    ];

    protected $casts = [
        'data' => 'array',
        'period_start' => 'date',
        'period_end' => 'date',
        'token_issued_at' => 'datetime',
        'first_viewed_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function issuePublicToken(): string
    {
        $token = Str::random(48);

        $this->forceFill([
            'public_token' => $token,
            'token_issued_at' => now(),
        ])->save();

        return $token;
    }

    /** Switch the link off. The report itself is untouched. */
    public function revokePublicToken(): void
    {
        $this->forceFill(['public_token' => null, 'token_issued_at' => null])->save();
    }

    public function publicUrl(): ?string
    {
        return $this->public_token ? route('ad-reports.public', $this->public_token) : null;
    }

    public function markViewed(): void
    {
        if ($this->first_viewed_at !== null) {
            return;
        }

        $this->forceFill(['first_viewed_at' => now()])->save();
    }

    /** "September 2026" -- the label the report itself carries, else derived. */
    public function periodLabel(): string
    {
        return $this->data['report']['period']['label']
            ?? $this->period_start->format('F Y');
    }
}
