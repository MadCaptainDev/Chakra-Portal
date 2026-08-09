<?php

namespace App\Http\Controllers;

use App\Models\PortfolioCategory;
use App\Models\PortfolioItem;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The public portfolio screen. Unauthenticated, and reads nothing but the
 * portfolio tables.
 */
class PublicPortfolioController extends Controller
{
    public function index(Request $request): View
    {
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
}
