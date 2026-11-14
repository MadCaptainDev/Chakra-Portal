<?php

namespace App\Http\Controllers;

use App\Models\PortfolioCategory;
use App\Models\PortfolioItem;
use App\Support\HomePage;
use App\Support\ImageVariant;
use App\Support\Seo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The public portfolio screen. Unauthenticated, and reads nothing but the
 * portfolio tables.
 */
class PublicPortfolioController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        // With nothing published there is no portfolio to show, so the screen
        // steps aside rather than advertising an empty one. The nav drops its
        // Work tab under the same rule.
        if (! PortfolioItem::published()->exists()) {
            return redirect()->route('home');
        }

        $categories = PortfolioCategory::visible()->ordered()->get();

        $items = PortfolioItem::visible()
            ->with('category')
            ->ordered()
            ->get()
            // A piece filed under a hidden tab must not leak through the "All"
            // view, which is not filtered by category at all.
            ->reject(fn (PortfolioItem $item) => $item->portfolio_category_id
                && ! $categories->contains('id', $item->portfolio_category_id))
            // Nothing to show yet (an Instagram piece still waiting for its cover).
            ->reject(fn (PortfolioItem $item) => $item->social_media_item_id && ! $item->thumbnailUrl() && ! $item->isUploaded())
            ->values();

        // A fresh order every visit, and never a run of one client: shuffled,
        // then dealt out one client at a time. Featured pieces still lead.
        $items = self::mixed($items->where('is_featured', true))->concat(self::mixed($items->where('is_featured', false)))->values();

        // The best performers, for the "top performer" pop-up.
        $stars = $items->filter(fn (PortfolioItem $i) => ($i->views ?? 0) >= self::STAR_VIEWS)->sortByDesc('views')->take(5)->values();

        // Deep links like /portfolio?category=weddings open on that tab.
        $requested = (string) $request->query('category', '');
        $activeModel = $categories->firstWhere('slug', $requested);
        $active = $activeModel?->slug ?? 'all';

        // A category tab is its own landing page for search ("healthcare
        // marketing Trichy"), so it gets its own title, canonical and list.
        $listed = $activeModel ? $items->where('portfolio_category_id', $activeModel->id) : $items;

        $cover = fn (PortfolioItem $i, int $w, int $h) => $i->thumbnail_path && is_file(public_path($i->thumbnail_path))
            ? ImageVariant::url($i->thumbnail_path, $w, $h) : null;

        // The hero's wall of reels, and the five biggest for the spotlight.
        $wall = $items->map(fn (PortfolioItem $i) => $cover($i, 360, 640))->filter()->values();
        // The five biggest, two per client at most, so the spotlight is not one brand.
        $hits = self::capPerClient($items->sortByDesc('views')->filter(fn (PortfolioItem $i) => $i->views), 2)->take(5)->values()
            ->map(fn (PortfolioItem $i) => [
                'id' => $i->id,
                'title' => $i->title,
                'client' => $i->clientLabel(),
                'hook' => $i->creative_hook,
                'summary' => $i->summary,
                'cover' => $cover($i, 540, 960),
                'src' => $i->playbackUrl(),
                'kind' => $i->isUploaded() ? 'file' : 'link',
                'pid' => $i->id,
                'detail' => $i->hasCaseStudy() ? route('portfolio.detail', $i) : null,
                'stats' => array_filter(['views' => $i->views, 'reach' => $i->reach, 'shares' => $i->shares, 'saves' => $i->saves]),
            ]);

        // Latest work, newest first: Instagram pieces from the last few weeks
        // (each one a real post, checked against the planner when it was added).
        // Up to two per client, dealt out a client at a time (each client's
        // newest first), so one busy account cannot fill the row.
        $recentPool = $items->filter(fn (PortfolioItem $i) => $i->social_media_item_id && $i->published_on?->gte(now()->subDays(self::RECENT_DAYS)))
            ->sortByDesc(fn (PortfolioItem $i) => [$i->published_on->toDateString(), $i->views ?? 0]);
        $recent = self::roundRobin(self::capPerClient($recentPool, 2))->take(8)->values()
            ->map(fn (PortfolioItem $i) => [
                'title' => $i->title,
                'client' => $i->clientLabel(),
                'cover' => $cover($i, 360, 640),
                'posted' => $i->published_on->format('j M'),
                'views' => $i->views,
                'href' => $i->hasCaseStudy() ? route('portfolio.detail', $i) : $i->video_url,
                'external' => ! $i->hasCaseStudy(),
            ])
            ->filter(fn ($r) => $r['cover'] && $r['href'])->values();

        return view('portfolio-public', [
            'recent' => $recent,
            'stars' => $stars,
            'wall' => $wall,
            'hits' => $hits,
            'brands' => HomePage::clients($items),
            'totals' => [
                'views' => (int) $items->sum('views'),
                'reach' => (int) $items->sum('reach'),
                'shares' => (int) $items->sum('shares'),
                'saves' => (int) $items->sum('saves'),
                'pieces' => $items->count(),
                'brands' => $items->map(fn (PortfolioItem $i) => $i->clientLabel())->filter()->unique()->count(),
            ],
            'categories' => $categories,
            'items' => $items,
            'activeCategory' => $active,
            'seo' => Seo::portfolioMeta($activeModel) + ['schema' => Seo::portfolioSchema($listed, $activeModel)],
        ]);
    }

    /** A reel at or above this many views gets the "top performer" pop-up. */
    public const STAR_VIEWS = 100_000;

    /** How recent a piece must be for the Latest work row. */
    public const RECENT_DAYS = 45;

    /**
     * The portfolio's numbers as they stand, for the page to poll: each
     * piece's views and reach, refreshed from Instagram on every sync.
     */
    /**
     * A reel's own video file, to play in the page's player instead of
     * Instagram's embed (which only shows a still and sends people to
     * Instagram). Instagram hands the file out for some reels -- not ones
     * with licensed music -- and its link expires, so it is fetched on tap
     * and kept 45 minutes. {url: null} means: open it on Instagram.
     */
    public function play(PortfolioItem $portfolioItem): JsonResponse
    {
        abort_unless($portfolioItem->is_visible, 404);

        if ($portfolioItem->isUploaded()) {
            return response()->json(['url' => $portfolioItem->playbackUrl()]);
        }

        $url = \Illuminate\Support\Facades\Cache::remember('pf-play:'.$portfolioItem->id, now()->addMinutes(45), function () use ($portfolioItem) {
            $media = $portfolioItem->socialMediaItem()->with('socialAccount')->first();
            if (! $media?->platform_media_id || ! $media->socialAccount?->access_token) {
                return '';
            }
            try {
                $r = \Illuminate\Support\Facades\Http::timeout(8)->get('https://graph.instagram.com/v21.0/'.$media->platform_media_id,
                    ['fields' => 'media_url', 'access_token' => $media->socialAccount->access_token]);

                return (string) ($r->json('media_url') ?? '');
            } catch (\Throwable) {
                return '';
            }
        });

        return response()->json(['url' => $url ?: null]);
    }

    /** Shuffled, then one client at a time, so no client runs on. */
    public static function mixed($items)
    {
        return self::roundRobin($items->shuffle());
    }

    /** Deal a list out one client at a time, keeping each client's own order. */
    public static function roundRobin($items)
    {
        $byClient = $items->groupBy(fn (PortfolioItem $i) => $i->client_id ?: 'n:'.$i->client_name)->map->values()->shuffle()->values();
        $out = collect();
        for ($round = 0; $out->count() < $items->count(); $round++) {
            foreach ($byClient as $pieces) {
                if (isset($pieces[$round])) {
                    $out->push($pieces[$round]);
                }
            }
        }

        return $out;
    }

    /** At most $n per client, in the given order. */
    public static function capPerClient($items, int $n)
    {
        $seen = [];

        return $items->filter(function (PortfolioItem $i) use (&$seen, $n) {
            $k = $i->client_id ?: 'n:'.$i->client_name;
            $seen[$k] = ($seen[$k] ?? 0) + 1;

            return $seen[$k] <= $n;
        })->values();
    }

    public function live(): JsonResponse
    {
        return response()->json([
            'synced_at' => PortfolioItem::visible()->max('instagram_refreshed_at'),
            'items' => PortfolioItem::visible()->whereNotNull('social_media_item_id')->get(['id', 'views', 'reach'])
                ->mapWithKeys(fn (PortfolioItem $i) => [$i->id => ['views' => $i->views, 'reach' => $i->reach]]),
        ])->header('Cache-Control', 'public, max-age=60');
    }

    /**
     * The case study for one piece: what we made, how far it went, and what it
     * did for the client.
     */
    public function show(PortfolioItem $portfolioItem): View
    {
        // A hidden piece has no public screen, and neither has one filed under
        // a hidden category -- the same rule the grid applies.
        abort_unless($portfolioItem->is_visible, 404);

        $portfolioItem->loadMissing(['category', 'client', 'platformTerm', 'formatTerm', 'objectiveTerm', 'tags', 'socialMediaItem']);

        if ($portfolioItem->category && ! $portfolioItem->category->is_visible) {
            abort(404);
        }

        // "More for this client" first, falling back to the same category so
        // the screen never dead-ends.
        // Same client first -- by the linked record where there is one, since
        // two pieces can spell a typed name differently and still be the same
        // client. Falls back to the category so the screen never dead-ends.
        $related = PortfolioItem::visible()
            ->whereKeyNot($portfolioItem->getKey())
            ->when(
                $portfolioItem->client_id,
                fn ($query) => $query->where('client_id', $portfolioItem->client_id),
                fn ($query) => $query->when(
                    filled($portfolioItem->client_name),
                    fn ($q) => $q->where('client_name', $portfolioItem->client_name),
                    fn ($q) => $q->where('portfolio_category_id', $portfolioItem->portfolio_category_id)
                )
            )
            ->ordered()
            ->take(4)
            ->get();

        return view('portfolio-detail', [
            'item' => $portfolioItem,
            'related' => $related,
            'seo' => Seo::caseStudy($portfolioItem),
        ]);
    }
}
