<?php

namespace App\Http\Controllers;

use App\Models\TaxonomyTerm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The master lists: platforms, formats, objectives, service types,
 * industries and tags.
 *
 * One screen for all of them, switched by ?type=. Adding a list is a constant
 * on TaxonomyTerm -- nothing here changes.
 */
class TaxonomyTermController extends Controller
{
    public function index(Request $request): View
    {
        $type = $request->string('type')->toString();

        if (! TaxonomyTerm::isKnownType($type)) {
            $type = TaxonomyTerm::TYPE_PLATFORM;
        }

        // One query for every tab's count, so switching tabs shows the shape
        // of the whole master list rather than just the open one.
        $counts = TaxonomyTerm::query()
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        return view('taxonomy.index', [
            'type' => $type,
            'meta' => TaxonomyTerm::TYPES[$type],
            'counts' => $counts,
            'terms' => TaxonomyTerm::ofType($type)->ordered()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['slug'] = TaxonomyTerm::uniqueSlug($data['type'], $data['name']);

        TaxonomyTerm::create($data);

        return $this->back($data['type'], TaxonomyTerm::label($data['type']).' added.');
    }

    public function update(Request $request, TaxonomyTerm $taxonomyTerm): RedirectResponse
    {
        $data = $this->validated($request, $taxonomyTerm);

        // The type is fixed at creation: moving a term between lists would
        // silently repoint every record using it.
        unset($data['type']);

        if ($data['name'] !== $taxonomyTerm->name) {
            $data['slug'] = TaxonomyTerm::uniqueSlug($taxonomyTerm->type, $data['name'], $taxonomyTerm->id);
        }

        $taxonomyTerm->update($data);

        return $this->back($taxonomyTerm->type, TaxonomyTerm::label($taxonomyTerm->type).' updated.');
    }

    /**
     * Deleting is allowed, and the database nulls every reference to it --
     * work is never removed along with a list entry. Retiring with the
     * "In use" toggle is the gentler option and is what the screen suggests.
     */
    public function destroy(TaxonomyTerm $taxonomyTerm): RedirectResponse
    {
        $type = $taxonomyTerm->type;
        $used = $taxonomyTerm->usageCount();

        $taxonomyTerm->delete();

        return $this->back($type, $used > 0
            ? TaxonomyTerm::label($type)." deleted. {$used} ".str('record')->plural($used).' no longer name one.'
            : TaxonomyTerm::label($type).' deleted.');
    }

    private function back(string $type, string $status): RedirectResponse
    {
        return redirect()->route('taxonomy.index', ['type' => $type])->with('status', $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?TaxonomyTerm $term = null): array
    {
        $type = $term?->type ?? $request->string('type')->toString();

        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(TaxonomyTerm::TYPES))],
            'name' => [
                'required', 'string', 'max:120',
                /*
                 * Two "Instagram Reels" in one list defeats the point of the
                 * list existing, and "instagram reels" is exactly the drift
                 * this table was built to stop.
                 *
                 * Compared in lower case explicitly rather than through a
                 * unique rule: whether `=` ignores case is a property of the
                 * column's collation, so the plain rule passes on MySQL and
                 * fails on SQLite. This behaves the same on both.
                 */
                function (string $attribute, mixed $value, callable $fail) use ($type, $term) {
                    $clash = TaxonomyTerm::ofType($type)
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $value))])
                        ->when($term, fn ($query) => $query->whereKeyNot($term->id))
                        ->exists();

                    if ($clash) {
                        $fail('That is already on this list.');
                    }
                },
            ],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $data['name'] = trim($data['name']);

        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
