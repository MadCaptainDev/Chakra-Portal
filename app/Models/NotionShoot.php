<?php

namespace App\Models;

use App\Support\TimesheetVenture;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A read-only mirror of one row from Notion's "Shoots" production-scheduling
 * database.
 *
 * NOT App\Models\Shoot -- that is the portal's own first-class, writable
 * shoot-booking record (client_id FK, crew, kits, scripts). This is a
 * one-way copy of what Notion says, refreshed on sync and never written
 * back to. The two share a name by one word and nothing else; a shoot
 * booked in the portal does not appear here, and a row here is never
 * promoted into `shoots` automatically.
 */
class NotionShoot extends Model
{
    use HasFactory;

    /** The config('notion.databases') key this model syncs from. */
    public const SOURCE = 'shoot';

    protected $fillable = [
        'notion_page_id',
        'notion_url',
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
     * The Notion "Client" select is free-text ("SVA", "Thillai Pet Clinic",
     * ...), matched against real clients the same way ContentItem::venture
     * already is -- see TimesheetVenture::normalize(). Resolved at read
     * time rather than at sync time so a client rename doesn't require a
     * re-sync to pick up.
     */
    public function clientLabel(): ?string
    {
        return TimesheetVenture::normalize($this->client) ?? $this->client;
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
