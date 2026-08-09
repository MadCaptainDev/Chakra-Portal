<?php

namespace App\Http\Controllers;

use App\Models\PortfolioCategory;
use App\Models\PortfolioItem;
use App\Models\TeamMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * The public landing page.
 *
 * Signed-in staff never see it -- they go to whichever home their role has.
 */
class LandingController extends Controller
{
    /** How many pieces the landing page shows before "See all work". */
    private const SHOWREEL_LIMIT = 6;

    public function __invoke(): View|RedirectResponse
    {
        if (auth()->check()) {
            return redirect()->route(auth()->user()->homeRoute());
        }

        $categories = PortfolioCategory::visible()->ordered()->get();

        $items = PortfolioItem::visible()
            ->with('category')
            ->ordered()
            ->get()
            ->reject(fn (PortfolioItem $item) => $item->portfolio_category_id
                && ! $categories->contains('id', $item->portfolio_category_id))
            // Featured work leads, then whatever the sort order says.
            ->sortByDesc(fn (PortfolioItem $item) => $item->is_featured ? 1 : 0)
            ->take(self::SHOWREEL_LIMIT)
            ->values();

        return view('landing', [
            'portfolioCategories' => $categories,
            'portfolioItems' => $items,
            'hasMorePortfolio' => PortfolioItem::visible()->count() > $items->count(),
            'teamMembers' => TeamMember::visible()->ordered()->get(),
        ]);
    }
}
