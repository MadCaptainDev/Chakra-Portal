<?php

namespace App\Support;

use App\Models\Client;
use App\Models\ContentItem;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Quantity tokens for invoice line items — resolved from Notion-published
 * content for the invoice month. Recurring schedules store the token;
 * generated and manual invoices store the resolved number.
 */
class InvoiceQuantityVariable
{
    public const PUBLISHED_REELS = 'published_reels';

    public const PUBLISHED_POSTS = 'published_posts';

    public const PUBLISHED_SHORTS = 'published_shorts';

    /** @var array<string, string> */
    public const TOKENS = [
        self::PUBLISHED_REELS => '{{published_reels}}',
        self::PUBLISHED_POSTS => '{{published_posts}}',
        self::PUBLISHED_SHORTS => '{{published_shorts}}',
    ];

    /** @var array<string, string> */
    public const LABELS = [
        self::PUBLISHED_REELS => 'Published reels',
        self::PUBLISHED_POSTS => 'Published posts',
        self::PUBLISHED_SHORTS => 'Published shorts',
    ];

    /** @var array<string, string> */
    private const SOURCE_FOR_KEY = [
        self::PUBLISHED_REELS => ContentItem::SOURCE_REEL,
        self::PUBLISHED_POSTS => ContentItem::SOURCE_POST,
        self::PUBLISHED_SHORTS => ContentItem::SOURCE_YOUTUBE,
    ];

    public static function isVariable(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        return in_array(self::normalizeToken($value), self::TOKENS, true);
    }

    public static function normalizeToken(string $value): string
    {
        return strtolower(trim($value));
    }

    public static function keyFor(mixed $value): ?string
    {
        if (! self::isVariable($value)) {
            return null;
        }

        $normalized = self::normalizeToken((string) $value);

        foreach (self::TOKENS as $key => $token) {
            if ($token === $normalized) {
                return $key;
            }
        }

        return null;
    }

    public static function accepts(mixed $value): bool
    {
        if (self::isVariable($value)) {
            return true;
        }

        return is_numeric($value) && (float) $value >= 0.01;
    }

    public static function resolve(mixed $value, Client $client, Carbon $month): float
    {
        if (! self::isVariable($value)) {
            if (! is_numeric($value)) {
                throw new InvalidArgumentException('Quantity must be numeric or a published-content variable.');
            }

            return (float) $value;
        }

        $key = self::keyFor($value);

        if ($key === null) {
            throw new InvalidArgumentException("Unknown quantity variable: {$value}");
        }

        return (float) self::countsFor($client, $month)[$key];
    }

    /**
     * Published counts for one client in one month, keyed for the UI and resolver.
     *
     * @return array{published_reels: int, published_posts: int, published_shorts: int}
     */
    public static function countsFor(Client $client, Carbon $month): array
    {
        [$since, $until] = ContentDashboard::monthRange($month);

        $base = $client->contentItems()
            ->where('status', 'Published')
            ->whereNotNull('published_date')
            ->whereBetween('published_date', [$since, $until]);

        $counts = [];

        foreach (self::SOURCE_FOR_KEY as $key => $source) {
            $counts[$key] = (clone $base)->where('source', $source)->count();
        }

        return $counts;
    }

    /**
     * @return list<array{key: string, token: string, label: string}>
     */
    public static function catalog(): array
    {
        return collect(self::TOKENS)->map(fn (string $token, string $key) => [
            'key' => $key,
            'token' => $token,
            'label' => self::LABELS[$key],
        ])->values()->all();
    }
}
