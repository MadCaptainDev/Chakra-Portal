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
            'managers' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::defaults(), 'confirmed'],
            // Derived from the constant rather than repeated as a literal, so
            // a new access level cannot be added and silently rejected here.
            'role' => ['required', Rule::in(array_keys(User::ROLES))],
            'manager_ids' => ['nullable', 'array'],
            'manager_ids.*' => [Rule::exists('users', 'id')],
            'employee_id' => ['nullable', 'exists:expenses,id'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['array'],
            'permissions.*.*' => ['string'],
        ]);

        DB::transaction(function () use ($validated, $request) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'role' => $validated['role'],
            ]);

            // sync() de-dupes, so the same name ticked twice is one row.
            $user->managers()->sync($validated['manager_ids'] ?? []);

            $this->applyPermissions($user, $validated);

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

    /**
     * The user's grants shaped for the matrix: ['scripts' => ['view','edit']].
     *
     * @return array<string, list<string>>
     */
    private function grantedMatrix(User $user): array
    {
        return $user->permissions
            ->groupBy('module')
            ->map(fn ($rows) => $rows->pluck('ability')->all())
            ->all();
    }

    public function edit(User $user): View
    {
        $user->loadMissing('employeeRecord', 'permissions', 'managers');

        return view('users.edit', [
            'user' => $user,
            'granted' => old('permissions', $this->grantedMatrix($user)),
            // Anybody but themselves: a person cannot be their own manager.
            'managers' => User::whereKeyNot($user->id)->orderBy('name')->get(['id', 'name']),
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
            'role' => ['required', Rule::in(array_keys(User::ROLES))],
            // Rule::notIn stops the obvious cycle. Longer chains are possible
            // and harmless -- nothing here walks the tree.
            'manager_ids' => ['nullable', 'array'],
            'manager_ids.*' => [Rule::notIn([$user->id]), Rule::exists('users', 'id')],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['array'],
            'permissions.*.*' => ['string'],
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

        $user->fill(collect($validated)->except(['avatar', 'remove_avatar', 'role', 'permissions', 'manager_ids'])->all());
        $user->role = $validated['role'];

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $this->applyAvatarUpload($request, $user);
        $user->save();

        // Absent means "none ticked", which is a real answer -- an employee can
        // be taken off every queue -- so it syncs to empty rather than being
        // skipped the way an unsent field usually is.
        $user->managers()->sync($validated['manager_ids'] ?? []);

        $this->applyPermissions($user, $validated);

        if ($user->employeeRecord) {
            $user->employeeRecord->update([
                'phone' => $validated['phone'] ?? null,
                'name' => $validated['name'],
            ]);
        }

        return redirect()->route('users.edit', $user)->with('status', 'Profile updated.');
    }

    /**
     * Store the module grants from the form.
     *
     * An admin's grants are cleared rather than saved. They already pass every
     * gate, so stored rows would be dead weight -- and dead weight that came
     * back to life the moment the account was demoted, quietly handing someone
     * access they were never re-granted.
     *
     * syncPermissions() filters every pair against the registry, so nothing a
     * hand-crafted form post invents can be persisted.
     *
     * @param  array<string, mixed>  $validated
     */
    private function applyPermissions(User $user, array $validated): void
    {
        $user->syncPermissions(
            $user->isAdmin() ? [] : ($validated['permissions'] ?? [])
        );
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
