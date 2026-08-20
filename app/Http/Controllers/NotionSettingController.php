<?php

namespace App\Http\Controllers;

use App\Models\ContentItem;
use App\Models\NotionSetting;
use App\Models\NotionShoot;
use App\Services\Notion\ContentSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * The studio's Notion integration token.
 *
 * Admin-only, beside WhatsApp and Instagram: this one token reads the
 * studio's entire content-planning and shoot-scheduling pipeline, and
 * connecting it is not work that gets delegated a piece at a time.
 *
 * Read-only by construction -- ContentSyncService never calls anything but
 * Notion's search/query/retrieve endpoints, and nothing here ever will.
 */
class NotionSettingController extends Controller
{
    public function edit(ContentSyncService $service): View
    {
        $settings = NotionSetting::current();

        return view('notion.edit', [
            'settings' => $settings,
            'sources' => config('notion.databases'),
            // Only when a key exists: these are live HTTP calls (cached for
            // notion.cache_ttl), and the service already swallows and logs
            // its own failures rather than throwing.
            'availability' => $settings->isConfigured() ? $service->sourceAvailability() : [],
            'lastSynced' => $this->lastSyncedAt(),
            'counts' => ContentItem::selectRaw('source, count(*) as c')->groupBy('source')->pluck('c', 'source')
                ->put('shoot', NotionShoot::count()),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'api_key' => ['nullable', 'string', 'max:255'],
        ]);

        /*
         * Blank means "leave it alone", not "clear it" -- the field cannot
         * show the current key back, it is a password input on a shared
         * screen, so an empty submit must not silently break every sync.
         */
        if (blank($validated['api_key'] ?? null)) {
            unset($validated['api_key']);
        }

        NotionSetting::current()->update($validated + [
            'updated_by_id' => $request->user()->id,
        ]);

        // A different key can see a different set of databases; the
        // discovery cache would otherwise report the OLD key's answer for
        // up to notion.cache_ttl.
        app(ContentSyncService::class)->forgetCaches();

        return redirect()
            ->route('notion.edit')
            ->with('status', 'Notion settings saved.');
    }

    /**
     * Re-check which databases the integration can see right now, bypassing
     * the discovery cache. Exists because "I just shared the database" would
     * otherwise take up to notion.cache_ttl to show up on this screen.
     */
    public function recheck(ContentSyncService $service): RedirectResponse
    {
        $service->forgetCaches();

        return redirect()->route('notion.edit')->with('status', 'Re-checked which databases are shared.');
    }

    /**
     * The most recent sync across BOTH tables -- ::max() is a raw aggregate
     * and bypasses the model's own 'datetime' cast, so it has to be parsed
     * here rather than trusted as already a Carbon instance.
     */
    private function lastSyncedAt(): ?Carbon
    {
        $values = array_filter([ContentItem::max('synced_at'), NotionShoot::max('synced_at')]);

        return $values === [] ? null : Carbon::parse(max($values));
    }
}
