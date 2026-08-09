<?php

namespace App\Http\Controllers;

use App\Models\PortfolioCategory;
use App\Models\PortfolioItem;
use App\Support\PublicUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin side of the public portfolio: the films themselves.
 *
 * Videos are uploaded here and served straight from public/uploads, so nothing
 * on the landing page depends on an external host staying up.
 */
class PortfolioItemController extends Controller
{
    public function index(): View
    {
        return view('portfolio.index', [
            'items' => PortfolioItem::with('category')->ordered()->get(),
            'categories' => PortfolioCategory::ordered()->withCount('items')->get(),
        ]);
    }

    public function create(): View
    {
        return view('portfolio.create', [
            'item' => new PortfolioItem(['is_visible' => true]),
            'categories' => PortfolioCategory::ordered()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        if ($request->hasFile('video')) {
            $data['video_path'] = PublicUpload::store($request->file('video'), 'portfolio/videos');
        }

        if ($request->hasFile('thumbnail')) {
            $data['thumbnail_path'] = PublicUpload::store($request->file('thumbnail'), 'portfolio/thumbnails');
        }

        PortfolioItem::create($data);

        return redirect()->route('portfolio.index')->with('status', 'Portfolio piece added.');
    }

    public function edit(PortfolioItem $portfolio): View
    {
        return view('portfolio.edit', [
            'item' => $portfolio,
            'categories' => PortfolioCategory::ordered()->get(),
        ]);
    }

    public function update(Request $request, PortfolioItem $portfolio): RedirectResponse
    {
        $data = $this->validated($request);

        // A replacement upload drops the file it replaces, so retries and
        // re-crops don't silently fill the disk.
        if ($request->hasFile('video')) {
            $previous = $portfolio->video_path;
            $data['video_path'] = PublicUpload::store($request->file('video'), 'portfolio/videos');
            PublicUpload::delete($previous);
        }

        if ($request->hasFile('thumbnail')) {
            $previous = $portfolio->thumbnail_path;
            $data['thumbnail_path'] = PublicUpload::store($request->file('thumbnail'), 'portfolio/thumbnails');
            PublicUpload::delete($previous);
        }

        $portfolio->update($data);

        return redirect()->route('portfolio.index')->with('status', 'Portfolio piece updated.');
    }

    public function destroy(PortfolioItem $portfolio): RedirectResponse
    {
        PublicUpload::delete($portfolio->video_path);
        PublicUpload::delete($portfolio->thumbnail_path);

        $portfolio->delete();

        return redirect()->route('portfolio.index')->with('status', 'Portfolio piece deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $rules = [
            'portfolio_category_id' => ['nullable', 'exists:portfolio_categories,id'],
            'title' => ['required', 'string', 'max:255'],
            'client_name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'video_url' => ['nullable', 'url', 'max:2048'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'video' => ['nullable', 'file', 'mimes:'.implode(',', PortfolioItem::VIDEO_EXTENSIONS), 'max:'.PortfolioItem::VIDEO_MAX_KB],
            'thumbnail' => ['nullable', 'image', 'max:4096'],

            // The optional case study.
            'summary' => ['nullable', 'string', 'max:2000'],
            'platform' => ['nullable', 'string', 'max:255'],
            'format' => ['nullable', 'string', 'max:255'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'objective' => ['nullable', 'string', 'max:255'],
            'published_on' => ['nullable', 'date'],
            'engagement_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'completion_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'watch_hours' => ['nullable', 'numeric', 'min:0'],
            'avg_watch_seconds' => ['nullable', 'numeric', 'min:0'],
            'sales_amount' => ['nullable', 'numeric', 'min:0'],
            'sales_before_amount' => ['nullable', 'numeric', 'min:0'],
            'benchmark_sales_amount' => ['nullable', 'numeric', 'min:0'],
            'roi' => ['nullable', 'numeric', 'min:0'],
            'before_after' => ['nullable', 'array', 'max:20'],
            'before_after.*.label' => ['nullable', 'string', 'max:80'],
            'before_after.*.before' => ['nullable', 'string', 'max:40'],
            'before_after.*.after' => ['nullable', 'string', 'max:40'],
        ];

        $counts = [
            ...PortfolioItem::PERFORMANCE_FIELDS,
            ...PortfolioItem::BUSINESS_FIELDS,
            ...PortfolioItem::BENCHMARK_FIELDS,
        ];

        foreach ($counts as $field) {
            // The rates and money columns above already have their own rule.
            $rules[$field] ??= ['nullable', 'integer', 'min:0'];
        }

        foreach (array_keys(PortfolioItem::CREATIVE_FIELDS) as $field) {
            $rules[$field] = ['nullable', 'string', 'max:500'];
        }

        $data = $request->validate($rules);

        unset($data['video'], $data['thumbnail']);

        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['is_visible'] = $request->boolean('is_visible');
        $data['is_featured'] = $request->boolean('is_featured');
        $data['show_business_impact'] = $request->boolean('show_business_impact');

        // A row is only worth keeping once all three boxes are filled in; the
        // form always posts five, mostly blank.
        $data['before_after'] = collect($data['before_after'] ?? [])
            ->filter(fn ($row) => filled($row['label'] ?? null)
                && filled($row['before'] ?? null)
                && filled($row['after'] ?? null))
            ->values()
            ->all() ?: null;

        // Empty boxes mean "no figure", not zero -- an untouched form must not
        // publish a case study claiming nought views.
        foreach ([...$counts, 'engagement_rate', 'completion_rate', 'watch_hours', 'avg_watch_seconds',
            'sales_amount', 'sales_before_amount', 'benchmark_sales_amount', 'roi'] as $field) {
            if (($data[$field] ?? null) === '') {
                $data[$field] = null;
            }
        }

        return $data;
    }
}
