<?php

namespace App\Http\Controllers;

use App\Models\CompetitorAccount;
use App\Models\CompetitorSetting;
use App\Services\Competitors\ConceptGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * The admin side of the competitor-analysis pipeline's three API keys.
 *
 * Admin-only, and not a permission module -- same reasoning as WhatsApp,
 * Notion and Push: connecting the studio's paid Apify/Gemini/Anthropic
 * accounts is not a job that gets delegated a piece at a time, and every key
 * on this screen carries a real per-use cost.
 */
class CompetitorSettingController extends Controller
{
    public function edit(): View
    {
        $settings = CompetitorSetting::current();

        return view('competitors.settings-edit', [
            'settings' => $settings,
            'trackedCount' => CompetitorAccount::count(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'apify_token' => ['nullable', 'string', 'max:255'],
            'gemini_api_key' => ['nullable', 'string', 'max:255'],
            'anthropic_api_key' => ['nullable', 'string', 'max:255'],
            'gemini_model' => ['required', 'string', 'max:100'],
        ]);

        // Blank means "leave it alone" for all three keys -- same rule
        // WhatsApp/Notion/Push use, and for the same reason: a secret field
        // cannot show the saved value back, so an empty submit is what
        // saving any OTHER field on this form looks like. Unlike PushSetting
        // there is no non-secret half of this screen -- every key here is a
        // paid API credential, so all three get this treatment, not just one.
        foreach (['apify_token', 'gemini_api_key', 'anthropic_api_key'] as $secret) {
            if (blank($validated[$secret] ?? null)) {
                unset($validated[$secret]);
            }
        }

        $settings = CompetitorSetting::current();
        $settings->fill($validated + ['updated_by_id' => $request->user()->id]);
        $settings->save();

        return redirect()->route('competitor-settings.edit')->with('status', 'Competitor analysis settings saved.');
    }

    /**
     * Prove the Anthropic key actually works with one cheap real call --
     * cheaper and faster than proving Apify or Gemini, and if the Anthropic
     * half is broken the "Generate concepts" button will be too, so this is
     * the one most worth checking from here.
     *
     * Deliberately does NOT catch: an admin pressing this wants Anthropic's
     * own error, word for word, the same way whatsapp:send and push:test do.
     */
    public function test(Request $request): RedirectResponse
    {
        $settings = CompetitorSetting::current();

        if (! $settings->hasAnthropic()) {
            return back()->with('status', 'Paste an Anthropic API key first, then save and try again.');
        }

        try {
            $result = ConceptGenerator::make()->generateConcepts(
                'A short test video of someone unboxing a product on camera.',
                'Reply with exactly one sentence confirming the connection works.',
            );
        } catch (Throwable $e) {
            return back()->with('status', 'Could not reach Anthropic: '.$e->getMessage());
        }

        return back()->with('status', 'Anthropic replied: '.Str::limit(trim($result), 200));
    }
}
