<x-app-layout title="Edit user">
    <x-slot name="header">
        <x-page-header :title="'Edit '.$user->name">
            <x-slot name="actions">
                <a href="{{ route('users.index') }}" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50">
                    Back to Users
                </a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="max-w-xl space-y-6">
        @if (session('status'))
            <div class="rounded-md bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">
                {{ session('status') }}
            </div>
        @endif

        <x-card class="p-4 sm:p-8">
            <form method="post" action="{{ route('users.update', $user) }}" enctype="multipart/form-data" class="space-y-6">
                @csrf
                @method('put')

                <div>
                    <x-input-label value="Profile Photo" />
                    <div class="mt-2 flex items-center gap-4">
                        <x-avatar :name="$user->name" :src="$user->avatarUrl()" size="lg" />
                        <div class="min-w-0 flex-1 space-y-2">
                            <input
                                id="avatar"
                                name="avatar"
                                type="file"
                                accept="image/*"
                                class="block w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-brand-50 file:text-brand-700 hover:file:bg-brand-100"
                            />
                            <p class="text-xs text-gray-500">JPG, PNG or WebP. Max 2 MB.</p>
                            @if ($user->avatar_path)
                                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" name="remove_avatar" value="1" class="rounded border-gray-300 text-brand-500 shadow-sm focus:ring-brand-400">
                                    Remove current photo
                                </label>
                            @endif
                            <x-input-error class="mt-1" :messages="$errors->get('avatar')" />
                        </div>
                    </div>
                </div>

                <div>
                    <x-input-label for="name" value="Name" />
                    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $user->name)" required />
                    <x-input-error class="mt-2" :messages="$errors->get('name')" />
                </div>

                <div>
                    <x-input-label for="email" value="Email" />
                    <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $user->email)" required />
                    <x-input-error class="mt-2" :messages="$errors->get('email')" />
                </div>

                <div>
                    <x-input-label for="phone" value="Phone" />
                    <x-text-input
                        id="phone"
                        name="phone"
                        type="tel"
                        class="mt-1 block w-full"
                        :value="old('phone', $user->phone ?: $user->employeeRecord?->phone)"
                    />
                    <x-input-error class="mt-2" :messages="$errors->get('phone')" />
                </div>

                <div>
                    <x-input-label for="bio" value="Bio" />
                    <textarea
                        id="bio"
                        name="bio"
                        rows="4"
                        maxlength="1000"
                        class="mt-1 block w-full border-gray-300 focus:border-brand-400 focus:ring-brand-400 rounded-md shadow-sm"
                        placeholder="A short introduction…"
                    >{{ old('bio', $user->bio) }}</textarea>
                    <x-input-error class="mt-2" :messages="$errors->get('bio')" />
                </div>

                <div>
                    <x-input-label for="role" value="Access" />
                    <select id="role" name="role" class="mt-1 block w-full border-gray-300 focus:border-brand-400 focus:ring-brand-400 rounded-md shadow-sm">
                        @foreach (\App\Models\User::ROLES as $value => $label)
                            <option value="{{ $value }}" @selected(old('role', $user->role) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <x-input-error class="mt-2" :messages="$errors->get('role')" />
                </div>

                <x-permission-matrix :granted="$granted" :role="old('role', $user->role)" />

                <div class="flex items-center gap-4">
                    <x-primary-button>Save Profile</x-primary-button>
                    @if ($user->employeeRecord)
                        <a href="{{ route('salaries.show', $user->employeeRecord) }}" class="text-sm font-semibold text-brand-500 hover:text-brand-600">
                            View payroll record &rarr;
                        </a>
                    @endif
                </div>
            </form>
        </x-card>

        <x-card class="p-4 sm:p-8">
            <header>
                <h2 class="text-lg font-medium text-gray-900">Set New Password</h2>
                <p class="mt-1 text-sm text-gray-600">
                    Set a password for this account. They can change it later from their own Profile page.
                </p>
            </header>

            <form method="post" action="{{ route('users.password', $user) }}" class="mt-6 space-y-6">
                @csrf
                @method('put')

                <div>
                    <x-input-label for="password" value="New Password" />
                    <x-text-input id="password" name="password" type="password" class="mt-1 block w-full" autocomplete="new-password" required />
                    <x-input-error :messages="$errors->updatePassword->get('password')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="password_confirmation" value="Confirm Password" />
                    <x-text-input id="password_confirmation" name="password_confirmation" type="password" class="mt-1 block w-full" autocomplete="new-password" required />
                </div>

                <div class="flex items-center gap-4">
                    <x-primary-button>Set Password</x-primary-button>

                    @if (session('password-updated'))
                        <p
                            x-data="{ show: true }"
                            x-show="show"
                            x-transition
                            x-init="setTimeout(() => show = false, 2000)"
                            class="text-sm text-gray-600"
                        >Saved.</p>
                    @endif
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
