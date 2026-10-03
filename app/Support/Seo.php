<?php

namespace App\Support;

use App\Models\PortfolioCategory;
use App\Models\PortfolioItem;
use Illuminate\Support\Str;

/**
 * Titles, descriptions and schema.org structured data for the public site:
 * the homepage, the portfolio and each case study.
 *
 * Aimed at local searches -- "digital marketing Trichy", "video production
 * Manapparai", "healthcare marketing Trichy" -- so the place names go where
 * Google reads them first (titles, descriptions, areaServed), stated once
 * and plainly rather than stuffed. Everything is built from the studio's
 * own records and config/studio.php; nothing here invents a claim.
 */
class Seo
{
    /** Google cuts descriptions at about this many characters. */
    private const DESCRIPTION_LENGTH = 155;

    /** The place line, said the same way everywhere. */
    public const PLACES = 'Trichy and Manapparai';

    public static function organizationId(): string
    {
        return url('/').'#organization';
    }

    /**
     * The studio itself: a marketing agency, where it works, how to reach it.
     * Contact fields are only included once config/studio.php has them.
     */
    public static function organization(array $services = []): array
    {
        $studio = config('studio');
        $address = array_filter([
            'streetAddress' => $studio['address']['street'] ?? null,
            'addressLocality' => $studio['address']['locality'] ?? null,
            'addressRegion' => $studio['address']['region'] ?? null,
            'postalCode' => $studio['address']['postal_code'] ?? null,
        ]);

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'MarketingAgency',
            '@id' => self::organizationId(),
            'name' => $studio['name'],
            'description' => 'Digital marketing and video content studio in '.self::PLACES.', Tamil Nadu: Instagram reels, YouTube, scripting, shooting, editing, social media management and reporting.',
            'url' => url('/'),
            'logo' => asset('images/chakra-logo.png'),
            'image' => asset('images/og-image.png'),
            'email' => $studio['email'] ?? null,
            'telephone' => $studio['phone'] ?? null,
            // A region on its own is not an address: only once there is a street or town.
            'address' => isset($address['streetAddress']) || isset($address['addressLocality'])
                ? ['@type' => 'PostalAddress', 'addressCountry' => $studio['address']['country']] + $address
                : null,
            'hasMap' => $studio['maps_url'] ?? null,
            'areaServed' => $studio['areas'],
            'sameAs' => array_values(array_filter($studio['social'] ?? [])) ?: null,
            'knowsAbout' => ['Digital marketing', 'Social media marketing', 'Instagram Reels', 'Video production', 'YouTube content', 'Content strategy'],
            'hasOfferCatalog' => $services === [] ? null : [
                '@type' => 'OfferCatalog',
                'name' => 'Services',
                'itemListElement' => array_map(fn (array $service) => [
                    '@type' => 'Offer',
                    'itemOffered' => ['@type' => 'Service', 'name' => $service[0], 'description' => $service[1], 'areaServed' => $studio['areas']],
                ], $services),
            ],
        ], fn ($value) => $value !== null);
    }

    public static function website(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            '@id' => url('/').'#website',
            'name' => config('studio.name'),
            'url' => url('/'),
            'publisher' => ['@id' => self::organizationId()],
            'inLanguage' => 'en-IN',
        ];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $trail  [name, url] pairs, home first
     */
    public static function breadcrumbs(array $trail): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(fn (array $crumb, int $i) => [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $crumb[0],
                'item' => $crumb[1],
            ], $trail, array_keys($trail)),
        ];
    }

    /**
     * Readable prose out of an Instagram caption: no hashtags, no
     * [keyword, lists], no "Follow @handle" lines, one line, cut at a word
     * near the length Google shows.
     */
    public static function description(?string $text, int $length = self::DESCRIPTION_LENGTH): string
    {
        $text = (string) $text;
        $text = preg_replace('/\[[^\]]*\]/u', ' ', $text);              // [keyword, lists]
        $text = preg_replace('/^\s*follow\b.*$/imu', ' ', $text);       // "Follow @account"
        $text = preg_replace('/(^|\s)[#@][\p{L}\p{N}_.]+/u', ' ', $text); // #tags and @handles
        $text = preg_replace('/[^\p{L}\p{N}\p{P}\s₹%+]/u', '', $text);  // emoji and symbols
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        return Str::limit($text, $length - 1, '…', preserveWords: true);
    }

    // ------------------------------------------------------------ portfolio

    /** @return array{title: string, description: string, canonical: string} */
    public static function portfolioMeta(?PortfolioCategory $category): array
    {
        if ($category) {
            return [
                'title' => $category->name.' — Portfolio | Chakra Productions, '.self::PLACES,
                'description' => self::description($category->name.' work by Chakra Productions, a digital marketing and video studio in '.self::PLACES.': reels, campaigns and case studies with the results they got.'),
                'canonical' => route('portfolio', ['category' => $category->slug]),
            ];
        }

        return [
            'title' => 'Portfolio — Reels, Video & Digital Marketing Work | Chakra Productions, Trichy',
            'description' => self::description('Case studies from Chakra Productions, a digital marketing studio in '.self::PLACES.': Instagram reels, YouTube and social media campaigns for healthcare, beauty, jewellery and local brands.'),
            'canonical' => route('portfolio'),
        ];
    }

    /**
     * The grid as a list search engines can follow, plus where it sits.
     *
     * @param  iterable<PortfolioItem>  $items
     */
    public static function portfolioSchema(iterable $items, ?PortfolioCategory $category): array
    {
        $meta = self::portfolioMeta($category);
        // Only pieces with a case study have a page the grid links to.
        $list = collect($items)->filter(fn (PortfolioItem $item) => $item->hasCaseStudy())->values();

        $trail = [['Home', url('/')], ['Portfolio', route('portfolio')]];
        if ($category) {
            $trail[] = [$category->name, $meta['canonical']];
        }

        return [
            [
                '@context' => 'https://schema.org',
                '@type' => 'CollectionPage',
                'name' => $meta['title'],
                'description' => $meta['description'],
                'url' => $meta['canonical'],
                'isPartOf' => ['@id' => url('/').'#website'],
                'about' => ['@id' => self::organizationId()],
                'mainEntity' => [
                    '@type' => 'ItemList',
                    'numberOfItems' => $list->count(),
                    'itemListElement' => $list->map(fn (PortfolioItem $item, int $i) => [
                        '@type' => 'ListItem',
                        'position' => $i + 1,
                        'url' => route('portfolio.detail', $item),
                        'name' => $item->title,
                    ])->all(),
                ],
            ],
            self::breadcrumbs($trail),
        ];
    }

    /**
     * One case study's title, description, share image and structured data.
     *
     * @return array{title: string, description: string, image: ?string, canonical: string, schema: list<array>}
     */
    public static function caseStudy(PortfolioItem $item): array
    {
        $client = $item->clientLabel() ? Str::squish($item->clientLabel()) : null;
        $category = $item->category?->name;
        $url = route('portfolio.detail', $item);
        $image = $item->thumbnailUrl();

        // The title: what it is, for whom, by whom -- the most specific first,
        // since that is the part a results page shows in full.
        // Google shows about 70 characters, so the brand goes first when it
        // does not fit, then the client -- the piece's own title never does.
        // Not when the title already names them, or the client is the studio.
        $forClient = $client
            && ! Str::contains(Str::lower($client), 'chakra')
            && ! Str::contains(Str::lower($item->title), Str::lower(Str::before($client, ' ')))
            ? ' — '.$client
            : '';
        $title = collect([
            $item->title.$forClient.' | Chakra Productions',
            $item->title.$forClient,
            $item->title.' | Chakra Productions',
        ])->first(fn (string $candidate) => mb_strlen($candidate) <= 70, $item->title);

        // The piece's own words first; a short caption ("First, we place the
        // baby in a warmer") is kept and given its context after it.
        $description = self::description($item->summary ?: $item->description);
        if (mb_strlen($description) < 60) {
            $context = collect([
                $item->formatLabel() === '9:16 vertical' ? 'Instagram reel' : ($item->platformLabel() ?: 'Video'),
                $client ? 'for '.$client : null,
                $category ? '('.$category.')' : null,
            ])->filter()->implode(' ').' by Chakra Productions, a digital marketing studio in '.self::PLACES.'.';

            $description = self::description(($description !== '' ? rtrim($description, '.').'. ' : '').$context);
        }

        $playable = $item->isUploaded() || $item->isMappedToInstagram() || filled($item->video_url);
        $uploaded = ($item->published_on ?? $item->created_at)?->toDateString();

        $work = array_filter([
            '@context' => 'https://schema.org',
            '@type' => $playable && $image ? 'VideoObject' : 'CreativeWork',
            '@id' => $url.'#work',
            'name' => $item->title,
            'description' => $description,
            'url' => $url,
            'thumbnailUrl' => $image ? [$image] : null,
            'image' => $image,
            'uploadDate' => $playable && $image ? $uploaded : null,
            'datePublished' => $uploaded,
            'contentUrl' => $item->isUploaded() ? $item->playbackUrl() : null,
            'duration' => $item->duration_seconds ? 'PT'.(int) $item->duration_seconds.'S' : null,
            'genre' => $category,
            'keywords' => $item->tags->pluck('name')->push($category)->filter()->implode(', ') ?: null,
            'creator' => ['@id' => self::organizationId()],
            'producer' => ['@id' => self::organizationId()],
            'sourceOrganization' => ['@id' => self::organizationId()],
            'about' => $client ? ['@type' => 'Organization', 'name' => $client] : null,
            'sameAs' => $item->isMappedToInstagram() ? $item->video_url : null,
            // Only what the page itself prints.
            'interactionStatistic' => $item->views ? [
                '@type' => 'InteractionCounter',
                'interactionType' => ['@type' => 'WatchAction'],
                'userInteractionCount' => (int) $item->views,
            ] : null,
        ], fn ($value) => $value !== null);

        $trail = [['Home', url('/')], ['Portfolio', route('portfolio')]];
        if ($item->category) {
            $trail[] = [$item->category->name, route('portfolio', ['category' => $item->category->slug])];
        }
        $trail[] = [$item->title, $url];

        return [
            'title' => $title,
            'description' => $description,
            'image' => $image,
            'canonical' => $url,
            'schema' => [$work, self::breadcrumbs($trail)],
        ];
    }
}
