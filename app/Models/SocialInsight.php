<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One metric, for one day or range, for an account or one of its media.
 *
 * See the migration for the two shapes this holds and why the upsert key is a
 * hash rather than the nullable columns themselves.
 */
class SocialInsight extends Model
{
    public const TYPE_TIME_SERIES = 'time_series';

    public const TYPE_TOTAL_VALUE = 'total_value';

    protected $guarded = [];

    protected $casts = [
        'value' => 'integer',
        'period_start' => 'date',
        'period_end' => 'date',
        'fetched_at' => 'datetime',
    ];

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function socialMediaItem(): BelongsTo
    {
        return $this->belongsTo(SocialMediaItem::class);
    }

    public function scopeAccountLevel(Builder $query): Builder
    {
        return $query->whereNull('social_media_item_id');
    }

    public function scopeMetric(Builder $query, string $metric): Builder
    {
        return $query->where('metric', $metric);
    }

    /**
     * Half-open range rather than whereBetween on two bare date strings.
     *
     * period_start is a DATE column, but Eloquent's plain 'date' cast writes
     * "2026-08-17 00:00:00" on save -- the cast only normalises what is READ
     * back, not the format used to WRITE. SQLite keeps that string as-is, and
     * "2026-08-17 00:00:00" sorts AFTER the bare "2026-08-17" used as the
     * upper bound, so a `<= $to->toDateString()` comparison silently drops
     * every row for the last day of the range -- typically today's, which is
     * the one most likely to be checked right after a sync. Comparing against
     * the first instant of the day AFTER $to is correct regardless of the
     * trailing time. TimesheetEntry::scopeForMonth documents and fixes the
     * same SQLite behaviour for worked_on; this is the same fix.
     */
    public function scopeBetween(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->where('period_start', '>=', $from->toDateString())
            ->where('period_start', '<', $to->copy()->addDay()->toDateString());
    }

    /**
     * Write or refresh one row.
     *
     * updateOrCreate on the dedupe key: a re-sync of a day already fetched
     * overwrites the value rather than adding a row, because Instagram's own
     * numbers for a day can still move for a while after it ends (a delayed
     * count catching up) and the cache should reflect the latest read.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function record(array $attributes): self
    {
        $key = hash('sha256', implode('|', [
            $attributes['social_account_id'],
            $attributes['social_media_item_id'] ?? 'account',
            $attributes['metric'],
            $attributes['period_start'],
            $attributes['period_end'] ?? $attributes['period_start'],
        ]));

        return self::updateOrCreate(
            ['dedupe_key' => $key],
            $attributes + [
                'period_end' => $attributes['period_end'] ?? $attributes['period_start'],
                'fetched_at' => now(),
            ]
        );
    }

    /**
     * The newest reading of each metric for each post.
     *
     * A post's views, reach and likes are lifetime totals that are stored
     * once per sync day, so adding the rows up counts a post as many times
     * as it has been synced. Only the latest day's row is the post's number.
     *
     * @param  iterable<int>  $itemIds
     * @param  array<int, string>  $metrics
     * @return array<int, array<string, int>> item id => metric => value
     */
    public static function latestPerItem(iterable $itemIds, array $metrics): array
    {
        $out = [];

        foreach (collect($itemIds)->unique()->chunk(500) as $ids) {
            $newest = DB::table('social_insights')
                ->selectRaw('social_media_item_id, metric, MAX(period_start) as period_start')
                ->whereIn('social_media_item_id', $ids)
                ->whereIn('metric', $metrics)
                ->groupBy('social_media_item_id', 'metric');

            DB::table('social_insights as si')
                ->joinSub($newest, 'n', fn ($join) => $join
                    ->on('si.social_media_item_id', '=', 'n.social_media_item_id')
                    ->on('si.metric', '=', 'n.metric')
                    ->on('si.period_start', '=', 'n.period_start'))
                ->orderBy('si.id')
                ->get(['si.social_media_item_id', 'si.metric', 'si.value'])
                ->each(function ($row) use (&$out) {
                    $out[$row->social_media_item_id][$row->metric] = (int) $row->value;
                });
        }

        return $out;
    }
}
