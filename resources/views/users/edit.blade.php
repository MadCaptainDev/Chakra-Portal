<x-app-layout title="Edit user">
    <x-slot name="header">
        <x-page-header :title="'Edit '.$user->name">
            <x-slot name="actions">
                <a href="{{ route('users.index') }}" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-white/5 border border-white/15 rounded-md font-semibold text-xs text-brand-100/80 uppercase tracking-widest hover:bg-white/[0.09]">
                    Back to Users
                </a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="max-w-xl space-y-6">
        @if (session('status'))
            <div class="rounded-md bg-green-400/10 border border-green-400/30 px-4 py-3 text-sm text-green-200">
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
                                class="block w-full text-sm text-brand-100/70 file:mr-3 file:py-2 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-white/5 file:text-brand-200 hover:file:bg-brand-400/20"
                            />
                            <p class="text-xs text-brand-100/60">JPG, PNG or WebP. Max 2 MB.</p>
                            @if ($user->avatar_path)
                                <label class="inline-flex items-center gap-2 text-sm text-brand-100/80">
                                    <input type="checkbox" name="remove_avatar" value="1" class="rounded bg-white/10 border-white/25 text-brand-400 shadow-sm focus:ring-brand-400">
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
                        class="mt-1 block w-full border-white/15 focus:border-brand-400 focus:ring-brand-400 rounded-md shadow-sm"
                        placeholder="A short introduction…"
                    >{{ old('bio', $user->bio) }}</textarea>
                    <x-input-error class="mt-2" :messages="$errors->get('bio')" />
                </div>

                <x-manager-picker :managers="$managers"
                                  :selected="old('manager_ids', $user->managers->pluck('id')->all())" />

                <x-permission-matrix :granted="$granted" :role="old('role', $user->role)" />

                <div class="flex items-center gap-4">
                    <x-primary-button>Save Profile</x-primary-button>
                    @if ($user->employeeRecord)
                        <a href="{{ route('salaries.show', $user->employeeRecord) }}" class="text-sm font-semibold text-brand-500 hover:text-brand-300">
                            View payroll record &rarr;
                        </a>
                    @endif
                </div>
            </form>
        </x-card>

        <x-card class="p-4 sm:p-8">
            <header>
                <h2 class="text-lg font-medium text-white">Set New Password</h2>
                <p class="mt-1 text-sm text-brand-100/70">
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
                            class="text-sm text-brand-100/70"
                        >Saved.</p>
                    @endif
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
