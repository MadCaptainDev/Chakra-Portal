<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ContentAccount;
use App\Models\ContentAccountVenture;
use App\Models\ContentItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Content accounts, their monthly targets, and which Notion ventures feed
 * them.
 *
 * Admin-only, beside the other Setup screens: this decides whose numbers
 * are whose on the Content Dashboard, and a wrong mapping there is a client
 * being told they got work they did not.
 *
 * The mapping is deliberately manual. Fuzzy name matching was tried on this
 * exact data and produced confident errors -- "thinkwithpriya" landing on
 * Riya because "p(riya)" contains it, "SVA Golds and Diamonds" landing on
 * SVA Silks on the shared "SVA" token -- see the
 * content_account_ventures migration.
 */
class ContentAccountController extends Controller
{
    public function edit(): View
    {
        return view('content-accounts.edit', [
            'clients' => Client::orderBy('name')->get(),
            'accounts' => ContentAccount::with(['client', 'ventures'])->get()
                ->sortBy(fn (ContentAccount $a) => [$a->client?->name ?? '', $a->name])
                ->values(),
            'unmapped' => ContentAccount::unmappedVentures(),
            // Item counts per venture, so a person deciding where "PR" goes
            // can see it is 60 videos rather than a stray typo.
            'ventureCounts' => ContentItem::query()
                ->selectRaw('venture, count(*) as items')
                ->whereNotNull('venture')->where('venture', '!=', '')
                ->groupBy('venture')->pluck('items', 'venture'),
        ]);
    }

    /**
     * Save the whole screen at once: account names, targets, and every
     * venture assignment.
     *
     * One form rather than a save button per row -- the common task is
     * "sort out the mapping", which touches many rows at once, and a screen
     * that makes that fifteen separate round trips is a screen people stop
     * using.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'names' => ['array'],
            'names.*' => ['required', 'string', 'max:255'],
            'targets' => ['array'],
            'targets.*' => ['nullable', 'integer', 'min:0', 'max:9999'],

            /*
             * An indexed list carrying the venture as a VALUE, never as a
             * form key. PHP rewrites dots and spaces in request keys to
             * underscores, so "Annamalai.mov" and "Surya’s Restaurant"
             * would arrive as different strings than they are stored -- and
             * would then never match a row.
             */
            'map' => ['array'],
            'map.*.venture' => ['required', 'string'],
            'map.*.account_id' => ['nullable', 'integer', 'exists:content_accounts,id'],
        ]);

        DB::transaction(function () use ($validated) {
            foreach ($validated['names'] ?? [] as $id => $name) {
                $account = ContentAccount::find($id);
                $account?->update([
                    'name' => $name,
                    // Blank clears the target here, unlike a secret field:
                    // "no target" is a real, meaningful state on this screen
                    // and has to be reachable.
                    'monthly_target' => $validated['targets'][$id] ?? null,
                ]);
            }

            foreach ($validated['map'] ?? [] as $row) {
                $venture = $row['venture'];
                $accountId = $row['account_id'] ?? null;

                if ($accountId === null) {
                    // Unassigned means unmapped, not "belongs to nothing" --
                    // the row goes away and the venture returns to the
                    // unmapped list where it stays visible.
                    ContentAccountVenture::where('venture', $venture)->delete();

                    continue;
                }

                ContentAccountVenture::updateOrCreate(
                    ['venture' => $venture],
                    ['content_account_id' => $accountId],
                );
            }
        });

        return redirect()->route('content-accounts.edit')->with('status', 'Content accounts saved.');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'name' => ['required', 'string', 'max:255'],
            'monthly_target' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        ContentAccount::create($validated);

        return redirect()->route('content-accounts.edit')->with('status', 'Account added.');
    }

    /**
     * Deleting an account releases its ventures rather than destroying
     * them: the venture rows cascade away, so the ventures reappear as
     * unmapped and their content is visibly unattributed instead of
     * silently vanishing from every total.
     */
    public function destroy(ContentAccount $contentAccount): RedirectResponse
    {
        $name = $contentAccount->name;
        $contentAccount->delete();

        return redirect()->route('content-accounts.edit')
            ->with('status', "Deleted {$name}. Its ventures are now unmapped.");
    }
}
