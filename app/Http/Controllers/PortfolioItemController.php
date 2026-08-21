<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\PortfolioCategory;
use App\Models\PortfolioItem;
use App\Models\SocialAccount;
use App\Models\SocialMediaItem;
use App\Models\TaxonomyTerm;
use App\Support\PublicUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin side of the public portfolio: the films themselves.
 *
 * Videos are uploaded here and served straight from public/uploads, so nothing
 * on the landing page depends on an external host staying up.
 */
class PortfolioItemController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim($request->string('q')->toString());
        $category = $request->string('category')->toString();
        $status = $request->string('status')->toString();

        $items = PortfolioItem::with(['category', 'client', 'tags'])
            ->when($search !== '', fn ($query) => $query->where(
                fn ($q) => $q->where('title', 'like', "%{$search}%")
                    ->orWhere('client_name', 'like', "%{$search}%")
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$search}%"))
            ))
            ->when($category === 'none', fn ($query) => $query->whereNull('portfolio_category_id'))
            ->when(is_numeric($category), fn ($query) => $query->where('portfolio_category_id', (int) $category))
            ->when($status === 'live', fn ($query) => $query->where('is_visible', true))
            ->when($status === 'draft', fn ($query) => $query->where('is_visible', false))
            ->when($status === 'featured', fn ($query) => $query->where('is_featured', true))
            ->when($status === 'no-video', fn ($query) => $query->whereNull('video_path')->whereNull('video_url'))
            ->ordered()
            ->get();

        return view('portfolio.index', [
            'items' => $items,
            'categories' => PortfolioCategory::ordered()->withCount('items')->get(),

            // Totals describe the whole portfolio, not the filtered view --
            // otherwise filtering makes the counters lie.
            'totals' => [
                'all' => PortfolioItem::count(),
                'live' => PortfolioItem::where('is_visible', true)->count(),
                'featured' => PortfolioItem::where('is_featured', true)->count(),
                'noVideo' => PortfolioItem::whereNull('video_path')->whereNull('video_url')->count(),
            ],
            'filters' => ['q' => $search, 'category' => $category, 'status' => $status],
        ]);
    }

    /**
     * Recent Instagram posts/reels for the client picked on the form, so
     * staff can map a piece to one instead of typing everything by hand.
     *
     * Not inside a scopeBindings() route group -- this endpoint is not
     * nested under clients/{client}/... the way the client-facing Instagram
     * routes are -- so the ownership check is done by hand: media is only
     * ever looked up THROUGH $client->socialAccounts(), which makes it
     * structurally impossible to return another client's account's media
     * regardless of what client_id is posted.
     */
    public function instagramMedia(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->can('portfolio.create') || $request->user()->can('portfolio.edit'),
            403
        );

        $client = Client::find($request->integer('client_id'));

        $account = $client?->socialAccounts()
            ->forPlatform(SocialAccount::PLATFORM_INSTAGRAM)
            ->connected()
            ->first();

        if (! $account) {
            // No client, no connected account, or (below) nothing cached --
            // the picker treats all three as one condition: nothing to show.
            return response()->json(['items' => []]);
        }

        $items = $account->socialMediaItems()
            ->with('insights')
            ->newestFirst()
            ->limit(25)
            ->get()
            ->map(fn (SocialMediaItem $item) => [
                'id' => $item->id,
                // thumbnail_url is frequently absent (Meta only sets it for
                // some media types) -- media_url is the same fallback
                // instagram/insights.blade.php's Content Performance table
                // already uses. A raw video file falling through here is
                // caught downstream: storeFromUrl() only accepts an image
                // content-type and quietly returns null on anything else.
                'thumbnail_url' => $item->thumbnail_url ?: $item->media_url,
                'permalink' => $item->permalink,
                'caption' => $item->shortCaption(80),
                'posted_at' => $item->posted_at?->format('j M Y'),
                'posted_at_iso' => $item->posted_at?->toDateString(),
                'type' => $item->typeLabel(),
                'reach' => $item->metricValue('reach'),
                'views' => $item->metricValue('views'),
            ]);

        return response()->json(['items' => $items]);
    }

    public function create(Request $request): View
    {
        // Everything the studio publishes now is a 9:16 Reel by default --
        // staff can still change it per piece, but the common case should
        // not need touching the pickers at all. Missing terms (an unseeded
        // install) just leave the field on "Not set", same as before.
        $defaultFormat = TaxonomyTerm::where('type', TaxonomyTerm::TYPE_FORMAT)->where('name', '9:16 vertical')->value('id');
        $defaultPlatform = TaxonomyTerm::where('type', TaxonomyTerm::TYPE_PLATFORM)->where('name', 'Instagram Reels')->value('id');

        $item = new PortfolioItem([
            'is_visible' => true,
            'format_id' => $defaultFormat,
            'platform_id' => $defaultPlatform,
        ]);
        $preselectClientId = $request->integer('client_id') ?: null;
        $preselectMedia = null;

        if ($preselectClientId && $request->integer('media_id')) {
            $preselectMedia = $this->resolveMappedMedia($request->integer('media_id'), $preselectClientId);
        }

        if ($preselectClientId) {
            // Pre-selects the <select> via old()/$item fallback in the form.
            $item->client_id = $preselectClientId;
        }

        return view('portfolio.create', $this->formData($item) + [
            'preselectMedia' => $preselectMedia,
        ]);
    }

    /**
     * Everything the form needs to draw its pickers.
     *
     * A retired term is kept in the list of the piece already using it, so
     * opening an old piece and saving it cannot silently drop the label.
     *
     * @return array<string, mixed>
     */
    private function formData(PortfolioItem $item): array
    {
        $lists = [];

        foreach (PortfolioItem::TERM_FIELDS as $field => $type) {
            $lists[$type] = TaxonomyTerm::options($type, $item->{$field});
        }

        // Tags are many, so every tag already on the piece is kept, not one.
        $lists[TaxonomyTerm::TYPE_TAG] = TaxonomyTerm::ofType(TaxonomyTerm::TYPE_TAG)
            ->where(fn ($query) => $query->active()->orWhereIn('id', $item->exists ? $item->tags->pluck('id') : []))
            ->ordered()
            ->get();

        return [
            'item' => $item,
            'categories' => PortfolioCategory::ordered()->get(),
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'lists' => $lists,
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $media = $this->resolveMappedMedia($data['social_media_item_id'] ?? null, $data['client_id'] ?? null);
        // Set via mapToInstagram() below, not mass assignment -- and an
        // unresolved (missing or spoofed) id must not fall through either.
        unset($data['social_media_item_id']);

        if ($request->hasFile('video')) {
            $data['video_path'] = PublicUpload::store($request->file('video'), 'portfolio/videos');
        }

        if ($request->hasFile('thumbnail')) {
            $data['thumbnail_path'] = PublicUpload::store($request->file('thumbnail'), 'portfolio/thumbnails');
        } elseif ($media && $this->mediaThumbnailSource($media)) {
            // Only when no file was uploaded in this request -- an uploaded
            // file always wins, the same precedence
            // PortfolioItem::playbackUrl() already gives video_path over
            // video_url. storeFromUrl() degrades to null on a dead/expired
            // CDN URL (or a non-image one, e.g. a raw video file behind the
            // media_url fallback) rather than throwing, so a save never
            // fails over this.
            $data['thumbnail_path'] ??= PublicUpload::storeFromUrl($this->mediaThumbnailSource($media), 'portfolio/thumbnails');
        }

        $item = PortfolioItem::create($data);

        if ($media) {
            $item->mapToInstagram($media);
        }

        $item->tags()->sync($this->tagIds($request));

        return redirect()->route('portfolio.index')->with('status', 'Portfolio piece added.');
    }

    public function edit(PortfolioItem $portfolio): View
    {
        $portfolio->load(['tags', 'socialMediaItem']);

        return view('portfolio.edit', $this->formData($portfolio));
    }

    public function update(Request $request, PortfolioItem $portfolio): RedirectResponse
    {
        $data = $this->validated($request);

        $wasMapped = $portfolio->social_media_item_id;
        $media = $this->resolveMappedMedia($data['social_media_item_id'] ?? null, $data['client_id'] ?? null);
        unset($data['social_media_item_id']);

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
        } elseif ($media && $media->id !== $wasMapped && $this->mediaThumbnailSource($media)) {
            // Only for a NEW mapping, not every save of an already-linked
            // item -- see mapToInstagram() vs refreshFromInstagram().
            $stored = PublicUpload::storeFromUrl($this->mediaThumbnailSource($media), 'portfolio/thumbnails');

            if ($stored) {
                PublicUpload::delete($portfolio->thumbnail_path);
                $data['thumbnail_path'] = $stored;
            }
        }

        $portfolio->update($data);

        if ($media && $media->id !== $wasMapped) {
            $portfolio->mapToInstagram($media);
        } elseif (! $media && $wasMapped) {
            // The client changed, or the picker's selection was cleared --
            // an explicit unlink, not silently keeping a stale link.
            $portfolio->forceFill(['social_media_item_id' => null])->save();
        }

        $portfolio->tags()->sync($this->tagIds($request));

        return redirect()->route('portfolio.index')->with('status', 'Portfolio piece updated.');
    }

    /**
     * Where to fetch a mapped item's thumbnail from. thumbnail_url is
     * frequently absent -- Meta only sets it for some media types, confirmed
     * against the real cache -- so this falls back to media_url exactly like
     * instagram/insights.blade.php's Content Performance table already does.
     * A raw video file behind that fallback isn't a special case here:
     * PublicUpload::storeFromUrl() only accepts an image content-type and
     * quietly returns null on anything else.
     */
    private function mediaThumbnailSource(SocialMediaItem $media): ?string
    {
        return $media->thumbnail_url ?: $media->media_url;
    }

    /**
     * The submitted social_media_item_id, re-verified server-side rather
     * than trusted: it must belong to an Instagram account connected to
     * THIS SAME client, closing both a spoofed hidden input and the "client
     * was changed after the picker loaded" case -- either one fails this
     * lookup and the piece simply saves unmapped.
     */
    private function resolveMappedMedia(mixed $mediaId, mixed $clientId): ?SocialMediaItem
    {
        if (! $mediaId || ! $clientId) {
            return null;
        }

        return SocialMediaItem::with('insights')
            ->whereKey((int) $mediaId)
            ->whereHas('socialAccount', fn ($query) => $query->where('client_id', (int) $clientId))
            ->first();
    }

    public function destroy(PortfolioItem $portfolio): RedirectResponse
    {
        PublicUpload::delete($portfolio->video_path);
        PublicUpload::delete($portfolio->thumbnail_path);

        $portfolio->delete();

        return redirect()->route('portfolio.index')->with('status', 'Portfolio piece deleted.');
    }

    /**
     * Tag ids that are genuinely tags. Anything else posted into tags[] is
     * dropped rather than trusted, so a hand-edited form cannot attach a
     * platform to a piece as though it were a keyword.
     *
     * @return array<int, int>
     */
    private function tagIds(Request $request): array
    {
        $ids = collect($request->input('tags', []))->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id);

        if ($ids->isEmpty()) {
            return [];
        }

        return TaxonomyTerm::ofType(TaxonomyTerm::TYPE_TAG)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $rules = [
            'portfolio_category_id' => ['nullable', 'exists:portfolio_categories,id'],
            'client_id' => ['nullable', 'exists:clients,id'],
            'social_media_item_id' => ['nullable', 'integer', 'exists:social_media_items,id'],
            'title' => ['required', 'string', 'max:255'],
            'client_name' => ['nullable', 'string', 'max:255'],
            'tags' => ['nullable', 'array', 'max:30'],
            'tags.*' => ['integer'],
            'description' => ['nullable', 'string', 'max:2000'],
            'video_url' => ['nullable', 'url', 'max:2048'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'video' => ['nullable', 'file', 'mimes:'.implode(',', PortfolioItem::VIDEO_EXTENSIONS), 'max:'.PortfolioItem::VIDEO_MAX_KB],
            'thumbnail' => ['nullable', 'image', 'max:4096'],

            // The optional case study.
            'summary' => ['nullable', 'string', 'max:2000'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
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

        // Each single-value list must be a term of its own type -- a format id
        // in the platform box would render nonsense on the public page.
        foreach (PortfolioItem::TERM_FIELDS as $field => $type) {
            $rules[$field] = ['nullable', Rule::exists('taxonomy_terms', 'id')->where('type', $type)];
        }

        $data = $request->validate($rules);

        unset($data['video'], $data['thumbnail'], $data['tags']);

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
