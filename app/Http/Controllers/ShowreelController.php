<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ContentItem;
use App\Models\PortfolioCategory;
use App\Models\PortfolioItem;
use App\Support\ImageVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * /showreel: a 15-second motion-graphics film of the studio's work, then a
 * landing page under it (resources/views/showreel.blade.php, animated by
 * resources/js/showreel.js).
 *
 * Everything on it is real and read live: the covers are the published
 * portfolio, biggest hits first; the logos are the ones uploaded on client
 * records; every number is counted from the database, rounded down, never up.
 */
class ShowreelController extends Controller
{
    /** Covers in the film's moving rows and in the work wall under it. */
    private const WORK_LIMIT = 16;

    public function __invoke(): View
    {
        $works = PortfolioItem::published()
            ->with(['category', 'client'])
            ->whereNotNull('thumbnail_path')
            ->get()
            ->filter(fn (PortfolioItem $item) => is_file(public_path($item->thumbnail_path)))
            // Biggest hits first: the front row and the first column are what
            // a visitor sees before anything else.
            ->sortByDesc(fn (PortfolioItem $item) => (int) $item->views)
            ->take(self::WORK_LIMIT)
            ->values()
            ->map(fn (PortfolioItem $item) => [
                'title' => $item->title,
                'client' => $item->clientLabel() ? Str::squish($item->clientLabel()) : null,
                'category' => $item->category?->name,
                'views' => $item->views ? self::compact((int) $item->views) : null,
                'cover' => ImageVariant::url($item->thumbnail_path, 360, 640),
                'url' => route('portfolio.detail', $item),
            ]);

        return view('showreel', [
            'works' => $works,
            'rows' => self::rows($works),
            'clients' => $this->clients(),
            'stats' => $this->stats(),
            'categories' => PortfolioCategory::visible()->ordered()->pluck('name'),
        ]);
    }

    /**
     * The film's three parallax rows: front, middle, back. With enough work
     * each row gets its own pieces, the strongest in front; with little, every
     * row shows all of it in a different order so no row is empty.
     *
     * @return array<int, Collection>
     */
    private static function rows(Collection $works): array
    {
        if ($works->count() >= 9) {
            $size = (int) ceil($works->count() / 3);

            return $works->chunk($size)->map->values()->pad(3, collect())->all();
        }

        return [
            $works,
            $works->reverse()->values(),
            $works->slice(1)->concat($works->take(1))->values(),
        ];
    }

    /**
     * Logos first -- every client with one uploaded -- then the brands the
     * public portfolio already names, as a wordmark until a logo is added on
     * their client record. Never the studio itself.
     *
     * @return Collection<int, array{name: string, logo: ?string}>
     */
    private function clients(): Collection
    {
        $withLogo = Client::query()
            ->whereNotNull('logo_path')
            ->orderBy('name')
            ->get()
            ->filter(fn (Client $client) => is_file(public_path($client->logo_path)))
            ->map(fn (Client $client) => [
                'name' => Str::squish($client->name),
                'logo' => ImageVariant::url($client->logo_path, 320, 200),
            ])
            ->values();

        $named = PortfolioItem::published()
            ->with('client')
            ->get()
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
     * The film's three numbers. Each is left out when there is nothing to
     * count, and totals are floored ("1,000+" for 1,026), so the page can
     * only ever undersell.
     *
     * @return list<array{display: string, label: string}>
     */
    private function stats(): array
    {
        $published = PortfolioItem::published();
        $views = (int) (clone $published)->sum('views');
        $topViews = (int) (clone $published)->max('views');
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
