<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * One publishing identity belonging to a client, with a monthly target per
 * content type -- see the migrations for why this is not simply the client,
 * and why one number per account was not enough.
 *
 * Membership is by explicit Notion venture string (content_account_ventures),
 * never by fuzzy name matching.
 */
class ContentAccount extends Model
{
    use HasFactory;

    /**
     * The content types a target can be set against, keyed by the
     * config('notion.databases') source they come from.
     *
     * Classification is by source database alone: the Reel Planner is
     * reels, the Post Planner is posts, the YT planner is shorts. Notion's
     * own post_type column disagrees with that on 189 rows, and is not used
     * here -- which planner a thing was planned in is the studio's own
     * answer to what it is.
     *
     * Stories are synced and counted but never targeted.
     */
    public const TARGETABLE = [
        ContentItem::SOURCE_REEL => 'Insta Reel',
        ContentItem::SOURCE_POST => 'Insta Post',
        ContentItem::SOURCE_YOUTUBE => 'YouTube Shorts',
    ];

    protected $fillable = [
        'client_id',
        'name',
        'target_reel',
        'target_post',
        'target_youtube',
    ];

    protected $casts = [
        'target_reel' => 'integer',
        'target_post' => 'integer',
        'target_youtube' => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function ventures(): HasMany
    {
        return $this->hasMany(ContentAccountVenture::class);
    }

    /** @return list<string> */
    public function ventureNames(): array
    {
        return $this->ventures->pluck('venture')->all();
    }

    /** The target for one source, or null when none is set. */
    public function targetFor(string $source): ?int
    {
        return array_key_exists($source, self::TARGETABLE)
            ? $this->{'target_'.$source}
            : null;
    }

    public function hasAnyTarget(): bool
    {
        foreach (array_keys(self::TARGETABLE) as $source) {
            if ($this->targetFor($source) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every set target added up, or null when none is set at all.
     *
     * Null rather than 0 on purpose: "committed to nothing" and "committed
     * to zero" read the same as a number and mean opposite things.
     */
    public function totalTarget(): ?int
    {
        $set = collect(array_keys(self::TARGETABLE))
            ->map(fn (string $source) => $this->targetFor($source))
            ->filter(fn (?int $t) => $t !== null);

        return $set->isEmpty() ? null : (int) $set->sum();
    }

    /**
     * Only accounts somebody has committed a number to -- what the Content
     * Dashboard shows. An account with no target has nothing to compare
     * against, so a row for it is a row of numbers with no verdict.
     */
    public function scopeTargeted(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            foreach (array_keys(self::TARGETABLE) as $source) {
                $q->orWhereNotNull('target_'.$source);
            }
        });
    }

    /**
     * Every distinct venture string that appears in synced Notion content
     * but has not been assigned to any account.
     *
     * Surfaced on the mapping screen and counted on the dashboard, rather
     * than silently omitted: content nobody has mapped is work the studio
     * did that no target is measuring, which is exactly the thing a person
     * needs told rather than hidden.
     *
     * @return Collection<int, object{venture: string, items: int}>
     */
    public static function unmappedVentures(): Collection
    {
        $mapped = ContentAccountVenture::pluck('venture')->all();

        return ContentItem::query()
            ->selectRaw('venture, count(*) as items')
            ->whereNotNull('venture')
            ->where('venture', '!=', '')
            ->when($mapped !== [], fn ($q) => $q->whereNotIn('venture', $mapped))
            ->groupBy('venture')
            ->orderByDesc('items')
            ->get();
    }
}
