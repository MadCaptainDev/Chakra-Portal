<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\PushSetting;
use App\Models\PushToken;
use App\Support\BrowserSessions;
use App\Support\ManagesAvatars;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    use ManagesAvatars;

    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        $user = $request->user();
        $user->loadMissing('employeeRecord');

        /*
         * Server-side belt-and-braces for a token the browser-side revoke
         * missed -- a tab crashing mid-logout, a device factory-reset
         * without ever visiting /logout. There is no cron on this host to
         * run a sweep on a schedule, so it rides along on the one screen
         * that already reads this user's devices.
         */
        PushToken::where('user_id', $user->id)
            ->where(function ($query) {
                $stale = now()->subDays(60);
                $query->where('last_used_at', '<', $stale)
                    ->orWhere(fn ($q) => $q->whereNull('last_used_at')->where('created_at', '<', $stale));
            })
            ->delete();

        $pushSettings = PushSetting::current();

        return view('profile.edit', [
            'user' => $user,
            'devices' => BrowserSessions::for($user, $request->session()->getId()),
            'mcpTokens' => $user->mcpTokens()->latest()->get(),
            'pushConfigured' => $pushSettings->isConfigured(),
            'pushWebConfig' => $pushSettings->webConfig(),
            'pushVapidKey' => $pushSettings->vapid_public_key,
            'pushTokens' => $user->pushTokens()->latest()->get(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->safe()->except(['avatar', 'remove_avatar']);

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $this->applyAvatarUpload($request, $user);
        $user->save();

        // Keep the payroll phone in sync when this login is linked to a salary row.
        if ($user->employeeRecord && array_key_exists('phone', $validated)) {
            $user->employeeRecord->update(['phone' => $validated['phone']]);
        }

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     *
     * Employee logins are issued by admins — only admins may self-delete.
     */
    public function destroy(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $this->deleteAvatarFile($user->avatar_path);
        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
