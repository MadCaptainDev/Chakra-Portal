<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A competitor's public Instagram account, tracked read-only -- not a
 * SocialAccount. See the migration for why: that model assumes a genuine
 * OAuth-connected account, and a competitor is never that.
 */
class CompetitorAccount extends Model
{
    public const PLATFORM_INSTAGRAM = 'instagram';

    protected $fillable = [
        'username',
        'platform',
        'client_id',
        'notes',
        'is_active',
        // Normally written by CompetitorScraper after a scrape, not typed
        // into a form -- but unlike SocialAccount::$access_token these carry
        // no credential, so there is no reason to force every write through
        // forceFill(). Kept mass-assignable for the same reason a test (or a
        // future admin "edit stats by hand" fallback) can just create()/
        // update() one directly.
        'profile_pic_url',
        'followers_count',
        'avg_views_30d',
        'last_scraped_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'followers_count' => 'integer',
        'avg_views_30d' => 'integer',
        'last_scraped_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function reels(): HasMany
    {
        return $this->hasMany(CompetitorReel::class);
    }

    public function handle(): string
    {
        return '@'.$this->username;
    }
}
