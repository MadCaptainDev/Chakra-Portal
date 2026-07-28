<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(): View
    {
        $settings = CompanySetting::current();

        return view('settings.edit', compact('settings'));
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'signature_name' => ['required', 'string', 'max:255'],
            'signature_title' => ['required', 'string', 'max:255'],
            'invoice_prefix' => ['required', 'string', 'max:20'],
            'footer_text' => ['required', 'string', 'max:255'],
            'logo' => ['nullable', 'image', 'max:2048'],
        ]);

        $settings = CompanySetting::current();

        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('logos', 'public');
            $validated['logo_path'] = 'storage/'.$path;
        }

        unset($validated['logo']);

        $settings->update($validated);

        return redirect()->route('settings.edit')->with('status', 'Settings updated.');
    }
}
