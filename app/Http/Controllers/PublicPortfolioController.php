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
            // A fresh order every visit, so every client's work gets seen;
            // featured pieces still lead.
            ->shuffle()
            ->sortByDesc(fn (PortfolioItem $item) => (int) $item->is_featured)
            ->values();

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
        $hits = $items->sortByDesc('views')->filter(fn (PortfolioItem $i) => $i->views)->take(5)->values()
            ->map(fn (PortfolioItem $i) => [
                'id' => $i->id,
                'title' => $i->title,
                'client' => $i->clientLabel(),
                'hook' => $i->creative_hook,
                'summary' => $i->summary,
                'cover' => $cover($i, 540, 960),
                'src' => $i->playbackUrl(),
                'kind' => $i->isUploaded() ? 'file' : 'link',
                'detail' => $i->hasCaseStudy() ? route('portfolio.detail', $i) : null,
                'stats' => array_filter(['views' => $i->views, 'reach' => $i->reach, 'shares' => $i->shares, 'saves' => $i->saves]),
            ]);

        return view('portfolio-public', [
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

    /**
     * The portfolio's numbers as they stand, for the page to poll: each
     * piece's views and reach, refreshed from Instagram on every sync.
     */
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
