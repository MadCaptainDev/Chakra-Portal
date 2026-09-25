<?php

namespace App\Models;

use App\Support\ProposalBlocks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * A designed proposal document with a no-login share link clients can read
 * and comment on. See the create_proposals_table migration for why the body
 * is one JSON list, and App\Support\ProposalBlocks for its shapes.
 */
class Proposal extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_VIEWED = 'viewed';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    public const STATUSES = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_SENT => 'Sent',
        self::STATUS_VIEWED => 'Viewed',
        self::STATUS_ACCEPTED => 'Accepted',
        self::STATUS_DECLINED => 'Declined',
    ];

    /**
     * The Meta template ProposalController::sendWhatsapp() falls back to when
     * the client has not messaged the studio in the last 24 hours. Must be
     * Meta-approved before that path can send -- run
     * `app:seed-proposal-ready-template` once, same as quotation_ready.
     */
    public const WHATSAPP_TEMPLATE = 'proposal_ready_v1';

    protected $fillable = [
        'client_id',
        'title',
        'status',
        'sections',
        'valid_until',
        'created_by_id',
    ];

    protected $casts = [
        'sections' => 'array',
        'valid_until' => 'date',
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

    public function comments(): HasMany
    {
        return $this->hasMany(ProposalComment::class);
    }

    /**
     * Every "Send on WhatsApp" attempt, newest first -- the same send
     * history Invoice and Quotation keep.
     */
    public function whatsappLogs(): MorphMany
    {
        return $this->morphMany(WhatsappSendLog::class, 'loggable')->latest();
    }

    /**
     * The name the WhatsApp message greets: the linked client, else the
     * client name on the cover.
     */
    public function recipientName(): string
    {
        return $this->client?->name ?: (($this->cover()['client_name'] ?? '') ?: 'there');
    }

    /** The free-text version of the message, sent inside the 24-hour window. */
    public function whatsappMessage(): string
    {
        return 'Hi '.$this->recipientName().', your proposal "'.$this->title.'" from Chakra is ready. '
            .'You can read it, comment on any section and download the PDF here: '.$this->publicUrl();
    }

    /**
     * Mint a fresh share link, replacing any live one -- same rule as
     * ClientBrief::issuePublicToken(): the old URL stops working at once.
     * A draft becomes "sent", since handing out the link is the send.
     */
    public function issuePublicToken(): string
    {
        $token = Str::random(48);

        $this->forceFill([
            'public_token' => $token,
            'token_issued_at' => now(),
            'status' => $this->status === self::STATUS_DRAFT ? self::STATUS_SENT : $this->status,
        ])->save();

        return $token;
    }

    public function revokePublicToken(): void
    {
        $this->forceFill([
            'public_token' => null,
            'token_issued_at' => null,
        ])->save();
    }

    public function publicUrl(): ?string
    {
        return $this->public_token ? route('proposals.public', $this->public_token) : null;
    }

    /**
     * The client opened the link. Stamped once; "sent" moves to "viewed" but
     * a proposal already accepted or declined keeps its decision.
     */
    public function markViewed(): void
    {
        if ($this->first_viewed_at !== null) {
            return;
        }

        $this->forceFill([
            'first_viewed_at' => now(),
            'status' => $this->status === self::STATUS_SENT ? self::STATUS_VIEWED : $this->status,
        ])->save();
    }

    /**
     * Sections normalised through ProposalBlocks, so a view never has to
     * guard against a missing key in hand-edited or older JSON.
     *
     * @return list<array{key: string, type: string, data: array<string, mixed>}>
     */
    public function normalizedSections(): array
    {
        return ProposalBlocks::normalizeSections($this->sections ?? []);
    }

    public function cover(): ?array
    {
        foreach ($this->normalizedSections() as $section) {
            if ($section['type'] === 'cover') {
                return $section['data'];
            }
        }

        return null;
    }

    /**
     * "Chakra App Studio · Proposal for Print Bazzar" -- the running footer
     * on every sheet, taken from the cover so it follows the client's name.
     */
    public function footerText(): string
    {
        $cover = $this->cover() ?? [];
        $by = $cover['prepared_by'] ?? '' ?: 'Chakra Productions';
        $for = $cover['client_name'] ?? '' ?: ($this->client?->name ?? $this->title);

        return $by.' · Proposal for '.$for;
    }

    /**
     * @return array<string, string> section key => "01 Executive Summary"
     */
    public function sectionLabels(): array
    {
        $labels = [];
        foreach ($this->normalizedSections() as $section) {
            $labels[$section['key']] = $section['type'] === 'cover'
                ? 'Cover'
                : (trim(($section['data']['number'] ?? '').' '.($section['data']['title'] ?? '')) ?: 'Untitled section');
        }

        return $labels;
    }

    /**
     * The studio mark on the cover: App Studio's own logo when the cover says
     * so and one has been uploaded (Settings), the production logo otherwise
     * -- same fallback as CompanySetting::logoPathFor().
     */
    public function studioLogoPath(CompanySetting $settings): ?string
    {
        $brand = $this->cover()['brand'] ?? 'app_studio';

        if ($brand === 'app_studio' && $settings->app_studio_logo_path && is_file(public_path($settings->app_studio_logo_path))) {
            return $settings->app_studio_logo_path;
        }

        return $settings->logo_path && is_file(public_path($settings->logo_path)) ? $settings->logo_path : null;
    }

    /** The prospect's logo: the cover's own, else the linked client's. */
    public function clientLogoPath(): ?string
    {
        $path = $this->cover()['client_logo'] ?? null ?: $this->client?->logo_path;

        return $path && is_file(public_path($path)) ? $path : null;
    }

    public function unresolvedCommentCount(): int
    {
        return $this->comments()->whereNull('resolved_at')->whereNull('user_id')->count();
    }
}
