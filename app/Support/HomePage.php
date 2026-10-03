<?php

namespace App\Support;

use App\Models\Client;
use App\Models\ContentItem;
use App\Models\PortfolioCategory;
use App\Models\PortfolioItem;
use App\Models\TeamMember;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Everything the public homepage shows (resources/views/home.blade.php): the
 * scroll-driven film at the top and the landing page under it.
 *
 * All of it is read live. The covers are the published portfolio, biggest
 * hit first; the logos are the ones uploaded on client records; the team is
 * the Team Page module; every number is counted from the database and
 * rounded down, never up, so the page can only ever undersell.
 */
class HomePage
{
    /** Covers in the film's moving rows. */
    private const FILM_LIMIT = 16;

    /** Pieces in the work wall before "See more". */
    public const WALL_LIMIT = 12;

    public static function data(): array
    {
        $published = self::featuredFirst(PortfolioItem::published()
            ->with(['category', 'client'])
            ->get()
            // Biggest hits first, then the admin's own order.
            ->sortBy([
                fn ($a, $b) => (int) $b->views <=> (int) $a->views,
                fn ($a, $b) => $a->sort_order <=> $b->sort_order,
            ])
            ->values());

        $cards = $published->map(fn (PortfolioItem $item) => [
            'title' => $item->title,
            'client' => $item->clientLabel() ? Str::squish($item->clientLabel()) : null,
            'category' => $item->category?->name,
            'views' => $item->views ? self::compact((int) $item->views) : null,
            'cover' => $item->thumbnail_path && is_file(public_path($item->thumbnail_path))
                ? ImageVariant::url($item->thumbnail_path, 360, 640)
                : null,
            'url' => route('portfolio.detail', $item),
        ]);

        $film = $cards->whereNotNull('cover')->take(self::FILM_LIMIT)->values();

        return [
            'works' => $cards->take(self::WALL_LIMIT)->values(),
            'hasMorePortfolio' => $cards->count() > self::WALL_LIMIT,
            'film' => $film,
            'rows' => self::rows($film),
            'clients' => self::clients($published),
            'stats' => self::stats(),
            'categories' => PortfolioCategory::visible()->ordered()->pluck('name'),
            'studio' => StudioNumbers::get(),
            'grid' => InstagramGrid::read(),
            'team' => TeamMember::visible()->ordered()->get()->map(fn (TeamMember $member) => [
                'name' => $member->name,
                'role' => $member->role,
                'bio' => $member->bio,
                'photo' => $member->photo_path && is_file(public_path($member->photo_path))
                    ? ImageVariant::url($member->photo_path, 480, 600)
                    : null,
            ]),
        ];
    }

    /**
     * Featured pieces lead, dealt out one category at a time -- every
     * category's best featured piece, then every category's second -- so a
     * single viral category cannot fill the wall on its own. Everything not
     * featured follows in the order it came in (biggest hits first).
     */
    private static function featuredFirst(Collection $byViews): Collection
    {
        [$featured, $rest] = $byViews->partition(fn (PortfolioItem $item) => $item->is_featured);

        $rounds = $featured
            ->groupBy(fn (PortfolioItem $item) => $item->portfolio_category_id ?? 0)
            ->flatMap(fn (Collection $items) => $items->values()->map(fn (PortfolioItem $item, int $round) => [$round, $item]))
            // Stable sort: inside a round, categories keep the order of
            // their best piece, which is views order.
            ->sortBy(fn (array $pair) => $pair[0])
            ->map(fn (array $pair) => $pair[1]);

        return $rounds->concat($rest)->values();
    }

    /**
     * The film's three parallax rows: front, middle, back. With enough work
     * each row gets its own pieces, the strongest in front; with little, every
     * row shows all of it in a different order so no row is empty.
     *
     * @return array<int, Collection>
     */
    private static function rows(Collection $film): array
    {
        if ($film->count() >= 9) {
            $size = (int) ceil($film->count() / 3);

            return $film->chunk($size)->map->values()->pad(3, collect())->all();
        }

        return [
            $film,
            $film->reverse()->values(),
            $film->slice(1)->concat($film->take(1))->values(),
        ];
    }

    /**
     * Logos first -- every client with one uploaded -- then the brands the
     * public portfolio already names, as a wordmark until a logo is added on
     * their client record. Never the studio itself.
     *
     * @return Collection<int, array{name: string, logo: ?string}>
     */
    private static function clients(Collection $published): Collection
    {
        $withLogo = Client::query()
            ->whereNotNull('logo_path')
            ->orderBy('name')
            ->get()
            ->filter(fn (Client $client) => is_file(public_path($client->logo_path)))
            ->map(fn (Client $client) => [
                'name' => Str::squish($client->publicName()),
                'logo' => ImageVariant::url($client->logo_path, 320, 200),
            ])
            ->values();

        $named = $published
            ->map(fn (PortfolioItem $item) => $item->clientLabel())
            ->filter()
            ->map(fn (string $name) => Str::squish($name))
            ->reject(fn (string $name) => Str::contains(Str::lower($name), 'chakra'))
            ->reject(fn (string $name) => $withLogo->contains('name', $name))
            ->unique()
            ->map(fn (string $name) => ['name' => $name, 'logo' => null])
            ->values();

        return $withLogo->concat($named);
    }

    /**
     * The film's numbers. Each is left out when there is nothing to count,
     * and totals are floored ("1,000+" for 1,026).
     *
     * @return list<array{display: string, label: string}>
     */
    private static function stats(): array
    {
        $views = (int) PortfolioItem::published()->sum('views');
        $topViews = (int) PortfolioItem::published()->max('views');
        $reels = ContentItem::query()->visible()
            ->where('source', ContentItem::SOURCE_REEL)
            ->where('status', 'Published')
            ->count();

        return array_values(array_filter([
            $views > 0 ? ['display' => self::compact($views).'+', 'label' => 'views across our portfolio'] : null,
            $topViews > 0 ? ['display' => self::compact($topViews), 'label' => 'views on a single reel'] : null,
            $reels >= 10 ? ['display' => self::floorRound($reels).'+', 'label' => 'reels posted for our clients'] : null,
        ]));
    }

    /** 4,927,492 → "4.9M"; 116,501 → "116K". Always rounded down. */
    public static function compact(int $number): string
    {
        if ($number >= 1_000_000) {
            return rtrim(rtrim(number_format(floor($number / 100_000) / 10, 1), '0'), '.').'M';
        }

        if ($number >= 1_000) {
            return floor($number / 1_000).'K';
        }

        return (string) $number;
    }

    /** 1,026 → "1,000"; 87 → "80". */
    private static function floorRound(int $number): string
    {
        $step = $number >= 1_000 ? 100 : 10;

        return number_format(intdiv($number, $step) * $step);
    }
}
