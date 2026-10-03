<?php

namespace App\Models;

use App\Services\WhatsappSender;
use App\Support\TimesheetVenture;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Client extends Model
{
    use HasFactory;

    /**
     * Every optional block a monthly Instagram report can carry, in the
     * order the report itself lays them out. Hero stats (followers, net
     * growth, reach, engagement, pieces published) and the studio-written
     * note are not on this list -- they are the report's headline, not a
     * discretionary section, and every client gets them.
     *
     * The catalog lives here rather than on MonthlyReportData/
     * MonthlyReportDocumentRenderer so the client settings screen, the
     * report screen's checklist, and the PDF renderer all read one list
     * instead of three that can drift apart.
     */
    public const REPORT_SECTIONS = [
        'follower_growth' => 'Follower growth, day by day',
        'engagement_breakdown' => 'Engagement breakdown',
        'age_breakdown' => 'Age breakdown',
        'gender_breakdown' => 'Gender breakdown',
        'top_cities' => 'Top cities',
        'top_posts' => 'The posts that worked hardest',
        'formats_published' => 'What we published',
        'shoots' => 'Shoots this month',
    ];

    /**
     * Every screen in the client portal the studio can decide one client
     * does not get, keyed by the part of its route name that follows
     * `client.` -- so a section is named the same thing in the sidebar, in
     * the route definition that guards it, and on the settings checklist,
     * instead of three lists that can drift apart.
     *
     * Overview is deliberately absent and is not an oversight: it is where
     * a client lands the moment they sign in, so "turned off" would have
     * nowhere to send them. Instagram Insights and the monthly report are
     * absent for a different reason -- they are reached from Social and
     * have no sidebar row of their own, so they ride on `social` rather
     * than being a section a studio has to think about separately.
     */
    public const PORTAL_SECTIONS = [
        'brief' => 'Brand Brief',
        'invoices' => 'Invoices',
        'work' => 'Work Delivered',
        'content-calendar' => 'Content Calendar',
        'portfolio' => 'Your Work',
        'shoots' => 'Shoots',
        'social' => 'Social',
        'scripts' => 'Script Approvals',
    ];

    /**
     * What a client does not get until somebody says otherwise.
     *
     * Your Work is the case-study gallery -- the marketing-facing pieces
     * the studio has published about a job, not the work delivered to the
     * client, which is its own section. Most clients have none linked, so
     * for most of them that row led to an empty page; it is on for the
     * clients whose gallery the studio actually wants them looking at, and
     * off for everyone else.
     *
     * Script Approvals is off for the same shape of reason: most clients
     * never see a script before it's shot, and the studio only wants the
     * extra sign-off step for the ones where it has asked for it.
     */
    public const PORTAL_SECTIONS_OFF_BY_DEFAULT = ['portfolio', 'scripts'];

    /**
     * Regular: full social media management -- Instagram connected,
     * targets set, Forecast tracks them, monthly reports go out. Occasion:
     * a bounded job (shoot-only, edit-only, or a one-off event like a
     * wedding) -- the studio hands the finished video back and the client
     * posts it themselves, so none of the above applies. `service_note`
     * (free text, occasion only) records the specific scope -- "Editing
     * only", "Wedding, one-time" -- deliberately not a closed enum: real
     * scopes vary more than a fixed list would hold, same reasoning as
     * client_team_members.role staying free text.
     */
    public const CLIENT_TYPE_REGULAR = 'regular';

    public const CLIENT_TYPE_OCCASION = 'occasion';

    public const CLIENT_TYPES = [
        self::CLIENT_TYPE_REGULAR => 'Regular',
        self::CLIENT_TYPE_OCCASION => 'Occasion',
    ];

    /** Staff alert when a client is about to run out of content -- see SeedContentDepletionTemplate and SendDepletionAlerts. */
    public const WHATSAPP_TEMPLATE_DEPLETION = 'content_depletion_v1';

    protected $fillable = [
        'name',
        'logo_path',
        'address',
        'email',
        'phone',
        'whatsapp_portal_enabled',
        'report_sections_disabled',
        'portal_sections_disabled',
        'notion_venture',
        'industry_id',
        'client_type',
        'service_note',
        'is_active',
        'forecast_alert_depletion_date',
        'forecast_alert_sent_at',
    ];

    protected $casts = [
        'whatsapp_portal_enabled' => 'boolean',
        'report_sections_disabled' => 'array',
        'portal_sections_disabled' => 'array',
        'is_active' => 'boolean',
        'forecast_alert_depletion_date' => 'date',
        'forecast_alert_sent_at' => 'datetime',
        'script_approval_token_issued_at' => 'datetime',
    ];

    /**
     * The name the public website shows -- the portfolio, the homepage. The
     * books (invoices, quotations, reports) keep using name; display_name
     * only exists for a brand that trades under a different name than the
     * one it is billed under.
     */
    public function publicName(): string
    {
        return filled($this->display_name) ? $this->display_name : $this->name;
    }

    public function isOccasion(): bool
    {
        return $this->client_type === self::CLIENT_TYPE_OCCASION;
    }

    public function isRegular(): bool
    {
        return ! $this->isOccasion();
    }

    /** Every screen built for full social-media management (targets, Forecast, reports) reads this. */
    public function scopeRegular(Builder $query): Builder
    {
        return $query->where('client_type', self::CLIENT_TYPE_REGULAR);
    }

    public function scopeOccasion(Builder $query): Builder
    {
        return $query->where('client_type', self::CLIENT_TYPE_OCCASION);
    }

    /**
     * Currently active vs paused/stopped -- a separate axis from
     * client_type (see is_active's own migration doc block for why the two
     * are not conflated).
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    /**
     * Whether one report section is on for this client by default --
     * unset/null (no preference ever saved) means every section, matching
     * the report's original all-sections behaviour with nothing to
     * backfill for a client that predates this setting.
     */
    public function reportSectionEnabled(string $key): bool
    {
        return ! in_array($key, $this->report_sections_disabled ?? [], true);
    }

    /**
     * Every REPORT_SECTIONS key this client's reports include by default --
     * what a fresh visit to the report screen (no query string yet)
     * pre-ticks.
     *
     * @return list<string>
     */
    public function defaultReportSections(): array
    {
        return array_values(array_filter(
            array_keys(self::REPORT_SECTIONS),
            fn (string $key) => $this->reportSectionEnabled($key)
        ));
    }

    /**
     * Whether this client's portal includes one section.
     *
     * The stored value is a list of what is *off*, same convention as
     * report_sections_disabled, but null resolves differently: there it
     * means "everything", here it means "nobody has chosen yet", and
     * PORTAL_SECTIONS_OFF_BY_DEFAULT answers instead. A client who
     * predates this setting and one created this morning therefore get
     * the same portal, with nothing backfilled for either.
     */
    public function portalSectionEnabled(string $key): bool
    {
        return ! in_array($key, $this->portal_sections_disabled ?? self::PORTAL_SECTIONS_OFF_BY_DEFAULT, true);
    }

    /**
     * Every PORTAL_SECTIONS key this client's portal shows -- the rows
     * their sidebar has, and what the studio's checklist pre-ticks.
     *
     * @return list<string>
     */
    public function enabledPortalSections(): array
    {
        return array_values(array_filter(
            array_keys(self::PORTAL_SECTIONS),
            fn (string $key) => $this->portalSectionEnabled($key)
        ));
    }

    /**
     * What a client nobody has chosen for gets -- what the *new* client
     * form pre-ticks, where there is no record to ask yet.
     *
     * @return list<string>
     */
    public static function defaultPortalSections(): array
    {
        return array_values(array_diff(
            array_keys(self::PORTAL_SECTIONS),
            self::PORTAL_SECTIONS_OFF_BY_DEFAULT
        ));
    }

    /**
     * A no-login link to this client's script approval queue -- same
     * convention as ClientBrief::issuePublicToken()/Proposal::issuePublicToken():
     * long and random rather than derived from the client id, one live token
     * at a time, and reissuing replaces it outright so a link sent to the
     * wrong number can simply be killed.
     */
    public function issueScriptApprovalToken(): string
    {
        $token = Str::random(48);

        $this->forceFill([
            'script_approval_token' => $token,
            'script_approval_token_issued_at' => now(),
        ])->save();

        return $token;
    }

    public function revokeScriptApprovalToken(): void
    {
        $this->forceFill([
            'script_approval_token' => null,
            'script_approval_token_issued_at' => null,
        ])->save();
    }

    public function scriptApprovalPublicUrl(): ?string
    {
        return $this->script_approval_token
            ? route('client.scripts.public', $this->script_approval_token)
            : null;
    }

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

    /** Costs the studio carried for this client and expects back. */
    public function advances(): HasMany
    {
        return $this->hasMany(ClientAdvance::class);
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
            ->visible()
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

    /**
     * The Notion mirror's shoots for this client -- broader than shoots()
     * above, which only has rows that were imported. A client is not
     * "nothing booked" just because nobody has opened the import screen;
     * see NotionShootImporter, which now runs on every sync so the two are
     * usually in step, but Forecast reads this one directly since it wants
     * whatever Notion currently says, not a snapshot of the last import.
     */
    public function notionShoots(): HasMany
    {
        return $this->hasMany(NotionShoot::class, 'client_id');
    }

    /** This client's own publishing targets, one row per account. */
    public function contentAccounts(): HasMany
    {
        return $this->hasMany(ContentAccount::class);
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
     * Whoever the studio wants this client to be able to put a name to --
     * shown on their own dashboard as "Your team". role is a free-text
     * label ("Editor", "Account Manager") chosen when the pairing is made,
     * not this app's own permission vocabulary.
     */
    public function teamMembers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'client_team_members')
            ->withPivot('role', 'is_account_manager')
            ->withTimestamps()
            ->orderBy('client_team_members.created_at');
    }

    /**
     * Whoever owns this client internally -- a real flag on the same pivot
     * teamMembers() reads, separate from `role` (which stays purely the
     * client-facing label it always was; a client can be told someone is
     * their "Account Manager" without that person being the one this app
     * routes internal alerts to, and vice versa). Usually one person, but
     * not enforced as exactly one: a client between account managers during
     * a handover is a real state, not an error.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function accountManagers(): \Illuminate\Support\Collection
    {
        return $this->teamMembers->filter(fn (User $user) => (bool) $user->pivot->is_account_manager)->values();
    }

    /**
     * Who an internal alert about this client should actually reach: the
     * account manager(s) if any are set, otherwise the broader fallback
     * list a caller supplies (e.g. everyone who can see the Forecast
     * module) -- so a client nobody's been explicitly assigned to still
     * gets covered rather than silently alerting no one.
     *
     * @param  \Illuminate\Support\Collection<int, User>  $fallback
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function alertRecipients(\Illuminate\Support\Collection $fallback): \Illuminate\Support\Collection
    {
        $managers = $this->accountManagers();

        return $managers->isNotEmpty() ? $managers : $fallback;
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
