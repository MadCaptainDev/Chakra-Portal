<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\User;
use App\Support\ManagesAvatars;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserController extends Controller
{
    use ManagesAvatars;

    public function index(): View
    {
        return view('users.index', [
            'users' => User::with('employeeRecord')->orderBy('role')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('users.create', [
            // Employee records that don't yet have a login.
            'unlinkedEmployees' => Expense::where('type', Expense::TYPE_SALARY)
                ->whereNull('user_id')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::defaults(), 'confirmed'],
            'role' => ['required', 'in:admin,employee'],
            'employee_id' => ['nullable', 'exists:expenses,id'],
        ]);

        DB::transaction(function () use ($validated) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'role' => $validated['role'],
            ]);

            if (! empty($validated['employee_id'])) {
                // Only link an actual, still-unlinked employee record, so a
                // crafted id can't hijack someone else's salary row.
                Expense::where('id', $validated['employee_id'])
                    ->where('type', Expense::TYPE_SALARY)
                    ->whereNull('user_id')
                    ->update(['user_id' => $user->id]);
            }
        });

        return redirect()->route('users.index')->with('status', 'Account created.');
    }

    public function edit(User $user): View
    {
        $user->loadMissing('employeeRecord');

        return view('users.edit', [
            'user' => $user,
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($user->id),
            ],
            'phone' => ['nullable', 'string', 'max:40'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'avatar' => ['nullable', 'image', 'max:2048'],
            'remove_avatar' => ['sometimes', 'boolean'],
            'role' => ['required', 'in:admin,employee'],
        ]);

        // Don't let the last admin demote themselves into a lock-out.
        if ($request->user()->id === $user->id && $validated['role'] !== User::ROLE_ADMIN) {
            $otherAdmins = User::where('role', User::ROLE_ADMIN)->where('id', '!=', $user->id)->exists();
            if (! $otherAdmins) {
                return redirect()
                    ->route('users.edit', $user)
                    ->withErrors(['role' => 'You are the only admin. Promote someone else first.']);
            }
        }

        $user->fill(collect($validated)->except(['avatar', 'remove_avatar', 'role'])->all());
        $user->role = $validated['role'];

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $this->applyAvatarUpload($request, $user);
        $user->save();

        if ($user->employeeRecord) {
            $user->employeeRecord->update([
                'phone' => $validated['phone'] ?? null,
                'name' => $validated['name'],
            ]);
        }

        return redirect()->route('users.edit', $user)->with('status', 'Profile updated.');
    }

    /**
     * Admin sets a new password for a staff account (no current-password check).
     */
    public function updatePassword(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $user->update([
            'password' => $validated['password'],
        ]);

        return redirect()
            ->route('users.edit', $user)
            ->with('status', 'Password updated.')
            ->with('password-updated', true);
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($request->user()->id === $user->id) {
            return redirect()->route('users.index')->with('error', 'You cannot remove your own account.');
        }

        try {
            $this->deleteAvatarFile($user->avatar_path);
            $user->delete();
        } catch (QueryException) {
            return redirect()->route('users.index')
                ->with('error', 'This user created invoices and cannot be removed.');
        }

        return redirect()->route('users.index')->with('status', 'Account removed.');
    }
}
