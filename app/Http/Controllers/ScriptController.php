<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScriptRequest;
use App\Models\Client;
use App\Models\ContentItem;
use App\Models\Script;
use App\Models\ScriptSection;
use App\Models\TaxonomyTerm;
use App\Models\User;
use App\Services\Notion\NotionScriptImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ScriptController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'q' => trim($request->string('q')->toString()),
            'client' => $request->string('client')->toString(),
            'status' => $request->string('status')->toString(),
            'writer' => $request->string('writer')->toString(),
            'mine' => $request->boolean('mine'),
        ];

        $scripts = Script::query()
            ->with(['client', 'writer', 'lastEditedBy', 'platformTerm'])
            ->when($filters['q'] !== '', fn ($query) => $query->where(
                fn ($inner) => $inner
                    ->where('title', 'like', "%{$filters['q']}%")
                    ->orWhere('campaign', 'like', "%{$filters['q']}%")
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$filters['q']}%"))
            ))
            ->when($filters['client'] !== '', fn ($query) => $query->where('client_id', $filters['client']))
            ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['writer'] !== '', fn ($query) => $query->where('writer_id', $filters['writer']))
            ->when($filters['mine'], fn ($query) => $query->where('writer_id', $request->user()->id))
            // Soonest deadline first among the dated ones, then most recently
            // touched -- a writer's next job is nearly always at the top.
            ->orderByRaw('due_on IS NULL')
            ->orderBy('due_on')
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->withQueryString();

        /*
         * The counters describe the whole board, not the filtered view. A
         * status tab that renumbered itself as you filtered would be telling
         * you about your own filter rather than about the work.
         */
        $counts = Script::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('scripts.index', [
            'scripts' => $scripts,
            'filters' => $filters,
            'counts' => $counts,
            'statuses' => Script::STATUSES,
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'writers' => $this->writers(),
            'openTotal' => Script::open()->count(),
            'mineTotal' => Script::forWriter($request->user())->open()->count(),
        ]);
    }

    public function create(Request $request): View
    {
        $attributes = [
            'status' => Script::STATUS_DRAFT,
            'priority' => Script::PRIORITY_NORMAL,
        ];

        // Arriving from a specific reel on the content board (see
        // ContentDashboardController) -- pre-fill what the board already
        // knows so writing a script for a reel doesn't mean re-typing its
        // title and client by hand.
        if ($contentItemId = $request->query('content_item_id')) {
            $item = ContentItem::find($contentItemId);

            if ($item) {
                $attributes['content_item_id'] = $item->id;
                $attributes['title'] = $item->title;
                $attributes['client_id'] = $item->venture
                    ? Client::whereHas('contentAccounts.ventures', fn ($q) => $q->where('venture', $item->venture))->value('id')
                    : null;
            }
        }

        return view('scripts.create', $this->formData(new Script($attributes)));
    }

    public function store(ScriptRequest $request): RedirectResponse
    {
        $script = new Script($request->validated());
        $script->created_by_id = $request->user()->id;
        $script->save();

        /*
         * A script with no blocks is an empty page and a bad first impression,
         * so a new one opens on the three every reel needs. They are ordinary
         * sections -- rename, reorder or delete them like any other.
         *
         * When this script was started from a reel Notion already holds a
         * script for, Body opens on that text rather than empty: retyping
         * something the studio has already written is not writing.
         */
        NotionScriptImporter::seedSections($script, $script->contentItem);

        return redirect()
            ->route('scripts.edit', $script)
            ->with('status', 'Script created. Start writing.');
    }

    public function edit(Script $script): View
    {
        // client.brief.answers feeds the brief drawer beside the editor.
        $script->load(['sections', 'client.brief.answers', 'lastEditedBy']);

        return view('scripts.edit', $this->formData($script) + [
            'commonHeadings' => ScriptSection::COMMON_HEADINGS,
        ]);
    }

    public function update(ScriptRequest $request, Script $script): RedirectResponse
    {
        $script->update($request->validated());

        return redirect()
            ->route('scripts.edit', $script)
            ->with('status', 'Details saved.');
    }

    /** The read-only render — what a view-only user gets, and what goes on set. */
    public function show(Script $script): View
    {
        $script->load(['sections', 'client.brief.answers', 'writer', 'editor', 'lastEditedBy', 'platformTerm', 'scriptTypeTerm', 'languageTerm', 'comments']);

        return view('scripts.show', ['script' => $script]);
    }

    public function destroy(Script $script): RedirectResponse
    {
        $script->delete();

        return redirect()->route('scripts.index')->with('status', 'Script deleted.');
    }

    /**
     * Everything both the create and edit forms need.
     *
     * Retired terms are kept in the picker of a script already using one, so
     * opening an old script does not silently drop its type on the next save.
     *
     * @return array<string, mixed>
     */
    private function formData(Script $script): array
    {
        return [
            'script' => $script,
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'writers' => $this->writers(),
            'statuses' => Script::STATUSES,
            'priorities' => Script::PRIORITIES,
            'platforms' => TaxonomyTerm::options(TaxonomyTerm::TYPE_PLATFORM, $script->platform_id),
            'scriptTypes' => TaxonomyTerm::options(TaxonomyTerm::TYPE_SCRIPT_TYPE, $script->script_type_id),
            'languages' => TaxonomyTerm::options(TaxonomyTerm::TYPE_LANGUAGE, $script->language_id),
        ];
    }

    /** Anyone with a login can be named as a writer or an editor. */
    private function writers()
    {
        return User::staff()->orderBy('name')->get(['id', 'name']);
    }
}
