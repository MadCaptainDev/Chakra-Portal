<?php

namespace App\Models;

use App\Support\TimesheetVenture;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A read-only mirror of one row from Notion's "Shoots" production-scheduling
 * database.
 *
 * NOT App\Models\Shoot -- that is the portal's own first-class, writable
 * shoot-booking record (client_id FK, crew, kits, scripts). This is a
 * one-way copy of what Notion says, refreshed on sync and never written
 * back to. A row here can be IMPORTED into a real Shoot (see
 * NotionShootImporter), which is the only bridge between the two.
 */
class NotionShoot extends Model
{
    use HasFactory;

    /** The config('notion.databases') key this model syncs from. */
    public const SOURCE = 'shoot';

    /**
     * Notion's shoot statuses mapped onto the portal's own.
     *
     * Notion carries production detail the portal's four statuses do not
     * (Editing and Review are both "the shoot happened, work continues"),
     * so they collapse to completed rather than inventing portal statuses
     * that nothing else in the app understands.
     */
    public const STATUS_MAP = [
        'Planned' => Shoot::STATUS_PLANNED,
        'Shooting' => Shoot::STATUS_CONFIRMED,
        'Editing' => Shoot::STATUS_COMPLETED,
        'Review' => Shoot::STATUS_COMPLETED,
        'Completed' => Shoot::STATUS_COMPLETED,
        'Cancelled' => Shoot::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'notion_page_id',
        'notion_url',
        'client_id',
        'title',
        'status',
        'client',
        'team',
        'host_model',
        'location',
        'shoot_date',
        'duration',
        'video_count',
        'gear_needed',
        'weather_forecast',
        'photo_url',
        'notion_created_at',
        'synced_at',
    ];

    protected $casts = [
        'shoot_date' => 'date',
        'duration' => 'decimal:2',
        'notion_created_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    /**
     * The portal client this shoot has been mapped to.
     *
     * Named mappedClient, not client: `client` is already a real column
     * holding Notion's raw free-text name, and Eloquent resolves an
     * attribute before a relation of the same name -- so a client()
     * relation here would be silently unreachable through $shoot->client
     * while still looking like it worked under with('client').
     *
     * The foreign key is named explicitly for the same reason: inferred
     * from the method name it would look for mapped_client_id.
     */
    public function mappedClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /** The portal shoot imported from this one, once it has been. */
    public function shoot(): HasOne
    {
        return $this->hasOne(Shoot::class);
    }

    /**
     * What to call this shoot's client on screen.
     *
     * The mapped client wins. TimesheetVenture::normalize() is only a
     * fallback for rows nobody has mapped yet: it is fuzzy, and on this
     * data it is confidently wrong in places ("SVA Golds and Diamonds"
     * resolves to SVA Silks on the shared "SVA" token), so it must never
     * override a person's explicit answer.
     */
    public function clientLabel(): ?string
    {
        return $this->mappedClient?->name
            ?? TimesheetVenture::normalize($this->getAttribute('client'))
            ?? $this->getAttribute('client');
    }

    /** The portal status this shoot's Notion status corresponds to. */
    public function portalStatus(): string
    {
        return self::STATUS_MAP[$this->status] ?? Shoot::STATUS_PLANNED;
    }

    /** Team multi_select arrives comma-joined; the card shows it as chips. */
    public function teamMembers(): array
    {
        if (! $this->team) {
            return [];
        }

        return collect(explode(',', $this->team))
            ->map(fn (string $name) => trim($name))
            ->filter()
            ->values()
            ->all();
    }

    public function isPast(): bool
    {
        return $this->shoot_date !== null && $this->shoot_date->isPast();
    }
}
