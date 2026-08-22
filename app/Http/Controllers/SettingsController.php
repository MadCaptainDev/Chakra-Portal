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
            'notification_email' => ['nullable', 'email', 'max:255'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'app_studio_logo' => ['nullable', 'image', 'max:2048'],
        ]);

        $settings = CompanySetting::current();

        if ($request->hasFile('logo')) {
            $previous = $settings->logo_path;

            $path = $request->file('logo')->store('logos', 'public');
            $validated['logo_path'] = 'storage/'.$path;

            $this->deletePreviousLogo($previous);
        }

        if ($request->hasFile('app_studio_logo')) {
            $previous = $settings->app_studio_logo_path;

            $path = $request->file('app_studio_logo')->store('logos', 'public');
            $validated['app_studio_logo_path'] = 'storage/'.$path;

            $this->deletePreviousLogo($previous);
        }

        unset($validated['logo'], $validated['app_studio_logo']);

        $settings->update($validated);

        return redirect()->route('settings.edit')->with('status', 'Settings updated.');
    }

    /**
     * Remove a replaced logo so uploads don't pile up.
     *
     * Only touches files this app wrote under storage/ -- the bundled default
     * lives at public/images/chakra-logo.png and must survive, or every
     * invoice loses its logo.
     */
    private function deletePreviousLogo(?string $logoPath): void
    {
        if (! $logoPath || ! str_starts_with($logoPath, 'storage/')) {
            return;
        }

        Storage::disk('public')->delete(substr($logoPath, strlen('storage/')));
    }
}
