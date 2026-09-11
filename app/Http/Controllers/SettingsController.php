<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use App\Support\PublicUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'quotation_prefix' => ['required', 'string', 'max:20'],
            'footer_text' => ['required', 'string', 'max:255'],
            'notification_email' => ['nullable', 'email', 'max:255'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'app_studio_logo' => ['nullable', 'image', 'max:2048'],
        ]);

        $settings = CompanySetting::current();

        if ($request->hasFile('logo')) {
            $previous = $settings->logo_path;

            $validated['logo_path'] = PublicUpload::store($request->file('logo'), 'logos');

            PublicUpload::delete($previous);
        }

        if ($request->hasFile('app_studio_logo')) {
            $previous = $settings->app_studio_logo_path;

            $validated['app_studio_logo_path'] = PublicUpload::store($request->file('app_studio_logo'), 'logos');

            PublicUpload::delete($previous);
        }

        unset($validated['logo'], $validated['app_studio_logo']);

        $settings->update($validated);

        return redirect()->route('settings.edit')->with('status', 'Settings updated.');
    }
}
