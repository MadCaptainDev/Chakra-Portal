<?php

namespace App\Support;

use App\Models\PortfolioItem;
use App\Models\SocialMediaItem;
use Illuminate\Support\Collection;

/**
 * "This is doing well and isn't in the portfolio yet."
 *
 * The bar to clear is the studio's own: the average views (falling back to
 * reach) across portfolio pieces that already carry a number, not a fixed
 * constant that would need revisiting as the account grows. A brand-new
 * portfolio with nothing scored yet falls back to a flat floor so the very
 * first pieces still have a bar to clear.
 */
class PortfolioSuggestions
{
    /** Used only until the portfolio has scored pieces of its own to average. */
    private const FALLBACK_FLOOR = 10000;

    /** How many candidates the portfolio screen's "Worth adding" strip shows at once. */
    public const DEFAULT_LIMIT = 6;

    /**
     * The single best-performing post/reel that is not yet a portfolio
     * piece, or null when nothing clears the bar.
     *
     * Used by the dashboard, which can only ever act on one at a time: its
     * suggestion links straight to the create form pre-filled with it
     * (client_id + media_id -- the same handoff a client's Instagram
     * Insights page's own "Add to portfolio" link already uses), and a link
     * can only pre-fill one destination. Adding this one is what surfaces
     * the next-best on the following load. The portfolio screen itself
     * shows several at once -- see top().
     *
     * @return array{media: SocialMediaItem, clientId: int, clientName: ?string, metric: string, value: int, bar: int}|null
     */
    public static function best(): ?array
    {
        return self::top(1)->first();
    }

    /**
     * The best-performing posts/reels that are not yet a portfolio piece,
     * highest first, up to $limit. Empty when nothing clears the bar.
     *
     * @return Collection<int, array{media: SocialMediaItem, clientId: int, clientName: ?string, metric: string, value: int, bar: int}>
     */
    public static function top(int $limit = self::DEFAULT_LIMIT): Collection
    {
        $bar = self::bar();

        $linkedIds = PortfolioItem::query()->whereNotNull('social_media_item_id')->pluck('social_media_item_id');

        // insights eager-loaded so metricValue() below reads the already
        // -fetched collection rather than one query per candidate.
        return SocialMediaItem::query()
            ->whereNotIn('id', $linkedIds)
            ->whereHas('socialAccount', fn ($query) => $query->connected()->whereNotNull('client_id'))
            ->with(['insights', 'socialAccount.client'])
            ->get()
            ->map(function (SocialMediaItem $item) {
                $views = $item->metricValue('views');
                $value = $views ?? $item->metricValue('reach');

                return $value === null ? null : [
                    'media' => $item,
                    'metric' => $views !== null ? 'views' : 'reach',
                    'value' => $value,
                ];
            })
            ->filter()
            ->filter(fn (array $row) => $row['value'] >= $bar)
            ->sortByDesc('value')
            ->take($limit)
            ->values()
            ->map(fn (array $row) => [
                'media' => $row['media'],
                'clientId' => $row['media']->socialAccount->client_id,
                'clientName' => $row['media']->socialAccount->client?->name,
                'metric' => $row['metric'],
                'value' => $row['value'],
                'bar' => $bar,
            ]);
    }

    private static function bar(): int
    {
        $scored = PortfolioItem::query()
            ->where(fn ($query) => $query->whereNotNull('views')->orWhereNotNull('reach'))
            ->get(['views', 'reach'])
            ->map(fn (PortfolioItem $item) => $item->views ?? $item->reach)
            ->filter();

        return $scored->isEmpty() ? self::FALLBACK_FLOOR : (int) round($scored->avg());
    }
}
