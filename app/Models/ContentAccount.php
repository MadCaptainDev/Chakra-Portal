<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * One publishing identity belonging to a client, with its own monthly
 * video target -- see the migration for why this is not simply the client.
 *
 * Membership is by explicit Notion venture string (content_account_ventures),
 * never by fuzzy name matching.
 */
class ContentAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'name',
        'monthly_target',
    ];

    protected $casts = [
        'monthly_target' => 'integer',
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
