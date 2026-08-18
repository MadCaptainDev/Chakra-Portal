<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who follows a connected Instagram account, as of the last sync.
 *
 * One row per account per dimension -- age×gender and city, each a CURRENT
 * snapshot overwritten on every sync, not a per-day history. See the
 * migration for why this doesn't fit social_insights, and
 * InstagramInsights::syncAudience() for what writes it.
 */
class SocialAudienceSnapshot extends Model
{
    public const DIMENSION_AGE_GENDER = 'age_gender';

    public const DIMENSION_CITY = 'city';

    protected $guarded = [];

    protected $casts = [
        'data' => 'array',
        'fetched_at' => 'datetime',
    ];

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function scopeDimension(Builder $query, string $dimension): Builder
    {
        return $query->where('dimension', $dimension);
    }

    /**
     * Share of followers by age bucket, summed across gender, as whole
     * percentages of this snapshot's own total -- highest first, matching
     * how the report reads it.
     *
     * @return list<array{label: string, value: int}>
     */
    public function ageBreakdown(): array
    {
        return $this->collapse(0, fn (string $age) => $age);
    }

    /**
     * Share of followers by gender, summed across age. Meta's own codes
     * (M/F/U) are never shown to a client -- translated to words here, the
     * one place that translation needs to happen.
     *
     * @return list<array{label: string, value: int}>
     */
    public function genderBreakdown(): array
    {
        return $this->collapse(1, fn (string $code) => match ($code) {
            'M' => 'Men',
            'F' => 'Women',
            default => 'Not stated',
        });
    }

    /**
     * The account's top cities by follower count -- raw counts, not a
     * percentage: a report reads "4,870 followers in Kochi" more plainly
     * than "39% of followers are in Kochi" when most followers have no
     * city on record at all (Meta only reports it where a follower set one).
     *
     * @return list<array{label: string, value: int}>
     */
    public function topCities(int $limit = 5): array
    {
        return collect($this->data)
            ->map(fn (array $row) => [
                'label' => (string) ($row['dimension_values'][0] ?? 'Unknown'),
                'value' => (int) ($row['value'] ?? 0),
            ])
            ->sortByDesc('value')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Collapses this snapshot's joint distribution down to one dimension,
     * summing away the other -- the age×gender snapshot answers both "by
     * age" and "by gender" from the same cached rows, no second API call.
     *
     * @return list<array{label: string, value: int}>
     */
    private function collapse(int $dimensionIndex, \Closure $labelFor): array
    {
        $sums = [];

        foreach ($this->data as $row) {
            $raw = $row['dimension_values'][$dimensionIndex] ?? null;

            if ($raw === null) {
                continue;
            }

            $label = $labelFor((string) $raw);
            $sums[$label] = ($sums[$label] ?? 0) + (int) ($row['value'] ?? 0);
        }

        $total = array_sum($sums);

        if ($total <= 0) {
            return [];
        }

        return collect($sums)
            ->map(fn (int $value, string $label) => ['label' => $label, 'value' => (int) round($value / $total * 100)])
            ->sortByDesc('value')
            ->values()
            ->all();
    }
}
