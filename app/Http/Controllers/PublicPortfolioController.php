<?php

namespace App\Http\Controllers;

use App\Models\PortfolioCategory;
use App\Models\PortfolioItem;
use Illuminate\Http\RedirectResponse;
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
            ->values();

        // Deep links like /portfolio?category=weddings open on that tab.
        $requested = (string) $request->query('category', '');
        $active = $categories->firstWhere('slug', $requested)?->slug ?? 'all';

        return view('portfolio-public', [
            'categories' => $categories,
            'items' => $items,
            'activeCategory' => $active,
        ]);
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
        ]);
    }
}
