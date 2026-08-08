<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserController extends Controller
{
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

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($request->user()->id === $user->id) {
            return redirect()->route('users.index')->with('error', 'You cannot remove your own account.');
        }

        try {
            $user->delete();
        } catch (QueryException) {
            return redirect()->route('users.index')
                ->with('error', 'This user created invoices and cannot be removed.');
        }

        return redirect()->route('users.index')->with('status', 'Account removed.');
    }
}
