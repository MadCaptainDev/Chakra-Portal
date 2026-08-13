<x-app-layout title="Add staff">
    <x-slot name="header">
        <x-page-header title="Add Staff Account" />
    </x-slot>

    <div class="max-w-2xl mx-auto">
        <x-card class="p-4 sm:p-6">
            <form method="POST" action="{{ route('users.store') }}">
                @csrf

                <div class="mb-4">
                    <x-input-label for="name" value="Name" />
                    <x-text-input id="name" name="name" type="text" class="mt-1" value="{{ old('name') }}" required autofocus />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>

                <div class="mb-4">
                    <x-input-label for="employee_id" value="Employee (optional)" />
                    <x-select id="employee_id" name="employee_id" class="mt-1">
                        <option value="">None</option>
                        @foreach ($unlinkedEmployees as $employee)
                            <option value="{{ $employee->id }}" @selected(old('employee_id') == $employee->id)>
                                {{ $employee->name }}@if ($employee->role) — {{ $employee->role }}@endif
                            </option>
                        @endforeach
                    </x-select>
                    <x-input-error :messages="$errors->get('employee_id')" class="mt-2" />
                </div>

                <div class="mb-4">
                    <x-input-label for="manager_id" value="Reports to" />
                    <x-select id="manager_id" name="manager_id" class="mt-1">
                        <option value="">Nobody — their days go to the admins</option>
                        @foreach ($managers as $manager)
                            <option value="{{ $manager->id }}" @selected(old('manager_id') == $manager->id)>{{ $manager->name }}</option>
                        @endforeach
                    </x-select>
                    <p class="text-xs text-gray-500 mt-1">
                        This person decides each of their days, and gets the team timesheet without anything else being ticked.
                    </p>
                    <x-input-error :messages="$errors->get('manager_id')" class="mt-2" />
                </div>

                <div class="mb-4">
                    <x-permission-matrix :granted="old('permissions', [])" :role="old('role', 'employee')" />
                </div>

                <div class="mb-4">
                    <x-input-label for="email" value="Email" />
                    <x-text-input id="email" name="email" type="email" class="mt-1" value="{{ old('email') }}" required />
                    <x-input-error :messages="$errors->get('email')" class="mt-2" />
                </div>

                <div class="mb-4">
                    <x-input-label for="password" value="Temporary Password" />
                    <x-text-input id="password" name="password" type="password" class="mt-1" required />
                    <x-input-error :messages="$errors->get('password')" class="mt-2" />
                    <p class="text-xs text-gray-500 mt-1">Share this with them directly - they can change it from their own Profile page after logging in.</p>
                </div>

                <div class="mb-6">
                    <x-input-label for="password_confirmation" value="Confirm Password" />
                    <x-text-input id="password_confirmation" name="password_confirmation" type="password" class="mt-1" required />
                </div>

                <div class="flex items-center gap-4">
                    <x-primary-button>Create Account</x-primary-button>
                    <a href="{{ route('users.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Cancel</a>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
