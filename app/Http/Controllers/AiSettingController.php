<?php

namespace App\Http\Controllers;

use App\Models\AdminAgentMessage;
use App\Models\AiSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The studio's Anthropic key, and the switch that decides whether the
 * assistant answers WhatsApp at all.
 *
 * Admin-only, beside Notion and WhatsApp, and for a sharper reason than
 * either: the thing this key turns on reads across every client's money and
 * answers whoever holds an admin's phone.
 */
class AiSettingController extends Controller
{
    public function edit(): View
    {
        $settings = AiSetting::current();

        return view('ai.edit', [
            'settings' => $settings,
            'answeredToday' => AdminAgentMessage::query()
                ->where('role', AdminAgentMessage::ROLE_USER)
                ->whereDate('created_at', today())
                ->count(),
            // What it has actually been asked, most recent first. The cheapest
            // possible answer to "is this thing behaving" is reading what it
            // said.
            'recent' => AdminAgentMessage::query()
                ->with('user')
                ->latest('id')
                ->limit(20)
                ->get(),
            'spend' => AdminAgentMessage::query()
                ->whereDate('created_at', '>=', today()->subDays(30))
                ->selectRaw('coalesce(sum(input_tokens), 0) as input, coalesce(sum(output_tokens), 0) as output')
                ->first(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'api_key' => ['nullable', 'string', 'max:255'],
            'provider' => ['required', 'string', Rule::in(array_keys(AiSetting::DEFAULT_MODELS))],
            // Blank is allowed and means "whatever this provider's default
            // is" -- see AiSetting::modelName(). Typing a model id is for
            // the day a provider retires one.
            'model' => ['nullable', 'string', 'max:64'],
            'is_active' => ['nullable', 'boolean'],
            'daily_answer_limit' => ['required', 'integer', 'min:0', 'max:10000'],
        ]);

        /*
         * Blank means "leave it alone", not "clear it" -- the field cannot
         * show the current key back, so an empty submit while changing the
         * model must not silently unkey the assistant. Clearing is its own
         * button, below.
         */
        if (blank($validated['api_key'] ?? null)) {
            unset($validated['api_key']);
        }

        AiSetting::current()->update($validated + [
            'is_active' => $request->boolean('is_active'),
            'updated_by_id' => $request->user()->id,
        ]);

        return redirect()->route('ai.edit')->with('status', 'Assistant settings saved.');
    }

    /**
     * Forget the key and switch the assistant off in one action.
     *
     * Separate from the form on purpose: this is what somebody reaches for
     * when they think a key has leaked, and it should not require noticing
     * that a blank field means "keep".
     */
    public function forget(Request $request): RedirectResponse
    {
        AiSetting::current()->update([
            'api_key' => null,
            'is_active' => false,
            'updated_by_id' => $request->user()->id,
        ]);

        return redirect()->route('ai.edit')->with('status', 'Key removed and the assistant switched off.');
    }
}
