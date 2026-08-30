<?php

namespace App\Models;

use App\Services\WhatsappSender;
use App\Support\TimesheetVenture;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'logo_path',
        'address',
        'email',
        'phone',
        'whatsapp_portal_enabled',
        'notion_venture',
        'industry_id',
    ];

    protected $casts = [
        'whatsapp_portal_enabled' => 'boolean',
    ];

    /**
     * A client whose WhatsApp number may use the self-service menu.
     */
    public function scopeWhatsappPortalEnabled(Builder $query): Builder
    {
        return $query->where('whatsapp_portal_enabled', true)->whereNotNull('phone');
    }

    /**
     * Match an inbound wa_id to an activated client portal, if any.
     */
    public static function findForWhatsappPortal(string $waId): ?self
    {
        $normalised = WhatsappSender::normalise($waId);
        $suffix = strlen($normalised) >= 10 ? substr($normalised, -10) : $normalised;

        return static::query()
            ->whatsappPortalEnabled()
            ->get()
            ->first(function (self $client) use ($normalised, $suffix) {
                $phone = WhatsappSender::normalise($client->phone);

                return $phone === $normalised || ($suffix !== '' && str_ends_with($phone, $suffix));
            });
    }

    /**
     * The client's logo, or null. Stored relative to public/, so asset()
     * resolves it without touching the storage symlink.
     */
    public function logoUrl(): ?string
    {
        return $this->logo_path ? asset($this->logo_path) : null;
    }

    /**
     * The social accounts the studio has been authorised to read for them.
     *
     * hasMany rather than hasOne: one client will eventually connect Instagram
     * and YouTube, and the day that happens should not be a migration.
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * Competitor Instagram accounts tracked for this client's market.
     */
    public function competitorAccounts(): HasMany
    {
        return $this->hasMany(CompetitorAccount::class)->orderBy('username');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Software Chakra App Studio built and maintains for this client -- the
     * one thing (besides invoices) that separates an App Studio client from
     * a Chakra Production one. Most clients have none.
     */
    public function saasProducts(): HasMany
    {
        return $this->hasMany(SaasProduct::class);
    }

    /**
     * Everything published for this client.
     *
     * Not a plain column join. Notion's venture field is free text typed by
     * whoever filled the planner, so one client arrives as "SVA Silks",
     * "Sva womenswear" and "SVA / RED-SAREE"; matching the column against
     * notion_venture found 737 of 1,217 items and silently returned nothing at
     * all for the six clients whose notion_venture is null.
     *
     * TimesheetVenture::normalize() already resolves every one of those -- an
     * alias table, then token matching -- so the spellings are gathered once
     * and matched with whereIn. It is the same mapping the timesheet uses,
     * which is the point: a client's hours and a client's output must agree
     * about who the client is.
     *
     * A query rather than a relation, deliberately. A HasMany would keep its
     * own `venture = notion_venture` condition and AND it with the list, which
     * is the bug this replaces; and for the six clients with a null
     * notion_venture it would compare against NULL and match nothing at all.
     */
    public function contentItems(): Builder
    {
        $ventures = TimesheetVenture::rawVenturesFor($this);

        return ContentItem::query()
            // No spellings means no work, not every client's work. An empty
            // whereIn is a no-op in some drivers, so the impossible value is
            // what keeps "nothing" meaning nothing.
            ->whereIn('venture', $ventures ?: ['\0__no_such_venture__']);
    }

    /** Shoots booked for this client, past and future. */
    public function shoots(): HasMany
    {
        return $this->hasMany(Shoot::class);
    }

    /** Logins the studio holds for this client's own accounts. */
    public function credentials(): HasMany
    {
        return $this->hasMany(ClientCredential::class)->orderBy('kind')->orderBy('label');
    }

    /** The login this client signs in with, if one has been issued. */
    public function login(): HasOne
    {
        return $this->hasOne(User::class)->where('role', User::ROLE_CLIENT);
    }

    /**
     * Published work for this client. Only the linked pieces -- a piece that
     * merely types the same name is not the same thing.
     */
    public function portfolioItems(): HasMany
    {
        return $this->hasMany(PortfolioItem::class);
    }

    /**
     * The client's sector, from the shared taxonomy.
     */
    public function industry(): BelongsTo
    {
        return $this->belongsTo(TaxonomyTerm::class, 'industry_id');
    }

    /**
     * What this client told us about their brand before we wrote for them.
     *
     * hasOne, enforced by a unique on client_briefs.client_id: one brand, one
     * brief. Null until the client saves something -- nothing creates a row on
     * a read, so a staff member opening this record does not start a brief on
     * the client's behalf.
     */
    public function brief(): HasOne
    {
        return $this->hasOne(ClientBrief::class);
    }
}
