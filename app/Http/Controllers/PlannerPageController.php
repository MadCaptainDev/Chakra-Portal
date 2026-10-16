<?php

namespace App\Http\Controllers;

use App\Models\PlannerRelease;
use App\Models\PlannerSession;
use App\Models\PlannerSetting;
use App\Services\Planner\PlannerRealtime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Chakra Planner's page in the portal. The planner itself is a desktop app,
 * not a portal screen; this is where people get it and where an admin wires
 * it up: download links and install steps, your signed-in computers, and
 * (Manage) the Ably key for live updates and the Notion webhook token.
 */
class PlannerPageController extends Controller
{
    /** The planner in the browser: a month of the four Notion planners. */
    public function web(Request $request): View
    {
        return view('planner.web', ['canEdit' => $request->user()->can('planner.edit')]);
    }

    /** The desktop app: downloads, signed-in computers and (Manage) live-update setup. */
    public function index(Request $request): View
    {
        $user = $request->user();
        $manage = $user->can('planner.manage');

        return view('planner.index', [
            'releases' => PlannerRelease::latestPerPlatform(),
            'mySessions' => PlannerSession::query()->where('user_id', $user->id)->latest('last_used_at')->get(),
            'manage' => $manage,
            'settings' => $manage ? PlannerSetting::current() : null,
            'allSessions' => $manage ? PlannerSession::query()->with('user:id,name')->latest('last_used_at')->get() : collect(),
            'history' => $manage ? PlannerRelease::query()->latest('id')->limit(15)->get() : collect(),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ably_key' => ['nullable', 'string', 'max:500', 'regex:/^[^:\s]+\.[^:\s]+:[^\s]+$/'],
            'clear_ably' => ['nullable', 'boolean'],
        ], ['ably_key.regex' => 'That does not look like an Ably API key (appId.keyId:secret).']);

        $settings = PlannerSetting::current();
        if ($request->boolean('clear_ably')) {
            $settings->forceFill(['ably_key' => null, 'updated_by_id' => $request->user()->id])->save();

            return back()->with('status', 'Live updates switched off. The app falls back to checking every few seconds.');
        }
        if (filled($data['ably_key'] ?? null)) {
            $settings->forceFill(['ably_key' => trim($data['ably_key']), 'updated_by_id' => $request->user()->id])->save();
            $ok = PlannerRealtime::publish('settings.updated', ['at' => now()->toIso8601String()]);

            return back()->with('status', $ok ? 'Ably key saved — live updates are on.' : 'Ably key saved, but Ably refused a test message. Check the key has Publish and Subscribe capabilities.');
        }

        return back();
    }

    public function forgetWebhook(Request $request): RedirectResponse
    {
        PlannerSetting::current()->forceFill(['notion_webhook_secret' => null, 'notion_webhook_secret_at' => null, 'updated_by_id' => $request->user()->id])->save();

        return back()->with('status', 'Webhook token forgotten. Create the subscription in Notion again and the new token will appear here.');
    }

    public function revokeSession(Request $request, int $session): RedirectResponse
    {
        $found = PlannerSession::query()->findOrFail($session);
        abort_unless($found->user_id === $request->user()->id || $request->user()->can('planner.manage'), 403);

        $found->delete();

        return back()->with('status', "Signed out {$found->device_name}.");
    }
}
