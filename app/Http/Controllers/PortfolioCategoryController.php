<?php

namespace App\Http\Controllers;

use App\Models\PortfolioCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The tabs across the top of the public portfolio.
 */
class PortfolioCategoryController extends Controller
{
    public function index(): View
    {
        return view('portfolio.categories', [
            'categories' => PortfolioCategory::ordered()->withCount('items')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['slug'] = PortfolioCategory::uniqueSlug($data['name']);

        PortfolioCategory::create($data);

        return redirect()->route('portfolio-categories.index')->with('status', 'Category added.');
    }

    public function update(Request $request, PortfolioCategory $portfolioCategory): RedirectResponse
    {
        $data = $this->validated($request);

        // The slug only follows a rename, so an existing public link keeps
        // working until the name itself actually changes.
        if ($data['name'] !== $portfolioCategory->name) {
            $data['slug'] = PortfolioCategory::uniqueSlug($data['name'], $portfolioCategory->id);
        }

        $portfolioCategory->update($data);

        return redirect()->route('portfolio-categories.index')->with('status', 'Category updated.');
    }

    /**
     * Items keep their row and fall back to "Uncategorised" -- deleting a tab
     * must never delete the work filed under it.
     */
    public function destroy(PortfolioCategory $portfolioCategory): RedirectResponse
    {
        $portfolioCategory->items()->update(['portfolio_category_id' => null]);
        $portfolioCategory->delete();

        return redirect()->route('portfolio-categories.index')->with('status', 'Category deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['is_visible'] = $request->boolean('is_visible');

        return $data;
    }
}
