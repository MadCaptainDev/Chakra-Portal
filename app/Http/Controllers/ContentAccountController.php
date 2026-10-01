<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ContentAccount;
use App\Models\ContentAccountVenture;
use App\Models\ContentItem;
use App\Models\NotionShoot;
use App\Models\NotionIgnoredName;
use App\Models\Shoot;
use App\Services\Notion\NotionShootImporter;
use App\Support\NotionConnections;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Everything that decides whose Notion content is whose: content accounts,
 * their per-type monthly targets, which ventures feed them, and which
 * portal client each Notion shoot belongs to.
 *
 * Admin-only, beside the other Setup screens. A wrong mapping here is a
 * client being told they got work they did not.
 *
 * The mapping is deliberately manual. Fuzzy name matching was tried on this
 * exact data and produced confident errors -- "thinkwithpriya" landing on
 * Riya because "p(riya)" contains it, "SVA Golds and Diamonds" landing on
 * SVA Silks on the shared token -- see the content_account_ventures
 * migration.
 */
class ContentAccountController extends Controller
{
    /**
     * The Notion ↔ Clients screen. Everything it shows comes from
     * NotionConnections::state(), which every action below also returns, so
     * the page redraws from the same truth after each change.
     */
    public function edit(): View
    {
        return view('content-accounts.edit', ['state' => NotionConnections::state()]);
    }

    /**
     * Save the whole screen at once: account names, per-type targets, every
     * venture assignment, and the shoot-client mapping.
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
            'targets.*' => ['array'],
            'targets.*.*' => ['nullable', 'integer', 'min:0', 'max:9999'],

            /*
             * An indexed list carrying the venture as a VALUE, never as a
             * form key. PHP rewrites dots and spaces in request keys to
             * underscores, so "Annamalai.mov" and "Surya's Restaurant"
             * would arrive as different strings than they are stored -- and
             * would then never match a row.
             */
            'map' => ['array'],
            'map.*.venture' => ['required', 'string'],
            'map.*.account_id' => ['nullable', 'integer', 'exists:content_accounts,id'],

            // Same shape, same reason, for Notion's free-text shoot clients.
            'shootMap' => ['array'],
            'shootMap.*.client' => ['required', 'string'],
            'shootMap.*.client_id' => ['nullable', 'integer', 'exists:clients,id'],
        ]);

        DB::transaction(function () use ($validated) {
            foreach ($validated['names'] ?? [] as $id => $name) {
                $account = ContentAccount::find($id);

                if (! $account) {
                    continue;
                }

                $targets = [];

                foreach (array_keys(ContentAccount::TARGETABLE) as $source) {
                    // Blank clears the target, unlike a secret field: "no
                    // target" is a real, meaningful state here and has to
                    // be reachable.
                    $targets['target_'.$source] = $validated['targets'][$id][$source] ?? null;
                }

                $account->update(['name' => $name] + $targets);
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

            foreach ($validated['shootMap'] ?? [] as $row) {
                NotionShoot::where('client', $row['client'])
                    ->update(['client_id' => $row['client_id'] ?? null]);
            }
        });

        return redirect()->route('content-accounts.edit')->with('status', 'Content accounts saved.');
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'name' => ['required', 'string', 'max:255'],
            'target_reel' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'target_post' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'target_youtube' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $account = ContentAccount::create($validated);

        return $request->expectsJson()
            ? $this->fresh("Added {$account->name}.", ['account_id' => $account->id])
            : redirect()->route('content-accounts.edit')->with('status', 'Account added.');
    }

    /**
     * Deleting an account releases its ventures rather than destroying
     * them: the venture rows cascade away, so the ventures reappear as
     * unmapped and their content is visibly unattributed instead of
     * silently vanishing from every total.
     */
    public function destroy(Request $request, ContentAccount $contentAccount): RedirectResponse|JsonResponse
    {
        $name = $contentAccount->name;
        $contentAccount->delete();

        return $request->expectsJson()
            ? $this->fresh("Deleted {$name}. Its ventures are back in To connect.")
            : redirect()->route('content-accounts.edit')->with('status', "Deleted {$name}. Its ventures are now unmapped.");
    }

    /**
     * Fill in the shoot-client mappings that match a portal client exactly,
     * so a person only has to decide the genuinely ambiguous ones.
     */
    public function autoMapShoots(Request $request, NotionShootImporter $importer): RedirectResponse|JsonResponse
    {
        $mapped = $importer->autoMapClients();
        $message = $mapped > 0
            ? "Matched {$mapped} shoot(s) to a client. Check the rest by hand."
            : 'No further shoots could be matched by name.';

        return $request->expectsJson()
            ? $this->fresh($message)
            : redirect()->route('content-accounts.edit')->with('status', $message);
    }

    // ------------------------------------------------------------------
    // The screen's own actions: one change at a time, saved at once, each
    // answering with the whole fresh state so the page cannot drift from
    // the database.
    // ------------------------------------------------------------------

    /** Connect a venture to an account -- or, with no account, disconnect it. */
    public function connectVenture(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'venture' => ['required', 'string', 'max:255'],
            'account_id' => ['nullable', 'integer', 'exists:content_accounts,id'],
        ]);

        $venture = $validated['venture'];
        $accountId = $validated['account_id'] ?? null;

        if ($accountId === null) {
            ContentAccountVenture::where('venture', $venture)->delete();

            return $this->fresh("Disconnected “{$venture}”.");
        }

        // venture is unique table-wide, so a move is an update: content can
        // never count for two accounts at once.
        ContentAccountVenture::updateOrCreate(['venture' => $venture], ['content_account_id' => $accountId]);
        NotionIgnoredName::where(['kind' => NotionIgnoredName::VENTURE, 'name' => $venture])->delete();

        $account = ContentAccount::with('client')->find($accountId);

        return $this->fresh("“{$venture}” now counts for {$account->client?->name} · {$account->name}.");
    }

    /**
     * The one-step answer for a venture whose client is not set up yet: make
     * the client (or use an existing one), make its account, connect the
     * venture -- one tap instead of three screens.
     */
    public function createAndConnect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'venture' => ['required', 'string', 'max:255'],
            'client_id' => ['nullable', 'required_without:client_name', 'integer', 'exists:clients,id'],
            'client_name' => ['nullable', 'required_without:client_id', 'string', 'max:255'],
            'account_name' => ['required', 'string', 'max:255'],
        ]);

        $account = DB::transaction(function () use ($validated) {
            $clientId = $validated['client_id'] ?? null;

            if ($clientId === null) {
                $clientId = Client::create([
                    'name' => trim($validated['client_name']),
                    'notion_venture' => $validated['venture'],
                ])->id;
            }

            $account = ContentAccount::create(['client_id' => $clientId, 'name' => trim($validated['account_name'])]);
            ContentAccountVenture::updateOrCreate(['venture' => $validated['venture']], ['content_account_id' => $account->id]);
            NotionIgnoredName::where(['kind' => NotionIgnoredName::VENTURE, 'name' => $validated['venture']])->delete();

            return $account->load('client');
        });

        return $this->fresh(
            "Created {$account->client->name} · {$account->name} and connected “{$validated['venture']}”.",
            ['account_id' => $account->id, 'client_id' => $account->client_id],
        );
    }

    /** Stop asking about a Notion name (or start again). Nothing is deleted. */
    public function ignore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in([NotionIgnoredName::VENTURE, NotionIgnoredName::SHOOT_CLIENT])],
            'name' => ['required', 'string', 'max:255'],
            'ignored' => ['required', 'boolean'],
        ]);

        $key = ['kind' => $validated['kind'], 'name' => $validated['name']];

        if ($validated['ignored']) {
            NotionIgnoredName::firstOrCreate($key);

            return $this->fresh("Ignoring “{$validated['name']}”.");
        }

        NotionIgnoredName::where($key)->delete();

        return $this->fresh("“{$validated['name']}” is back.");
    }

    /** An account's name or targets, edited in place. Only what is sent changes. */
    public function quickUpdate(Request $request, ContentAccount $contentAccount): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'target_reel' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:9999'],
            'target_post' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:9999'],
            'target_youtube' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $contentAccount->update($validated);

        return $this->fresh("Saved {$contentAccount->name}.");
    }

    /**
     * File every Notion shoot carrying this Client name under one portal
     * client -- the shoots already imported into the portal move with them.
     * Because it moves all of them, a name that was split across clients
     * (or part-filed) ends whole. Future syncs follow the same answer: see
     * NotionShootImporter::autoMapClients().
     */
    public function connectShootClient(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
        ]);

        $clientId = $validated['client_id'] ?? null;

        DB::transaction(function () use ($validated, $clientId) {
            $ids = NotionShoot::where('client', $validated['name'])->pluck('id');
            NotionShoot::whereIn('id', $ids)->update(['client_id' => $clientId]);
            Shoot::whereIn('notion_shoot_id', $ids)->update(['client_id' => $clientId]);
            NotionIgnoredName::where(['kind' => NotionIgnoredName::SHOOT_CLIENT, 'name' => $validated['name']])->delete();
        });

        $client = $clientId ? Client::find($clientId) : null;

        return $this->fresh($client
            ? "Shoots named “{$validated['name']}” are now {$client->name}’s."
            : "Shoots named “{$validated['name']}” have no client now.");
    }

    private function fresh(string $message, array $extra = []): JsonResponse
    {
        return response()->json(['state' => NotionConnections::state(), 'message' => $message] + $extra);
    }
}
