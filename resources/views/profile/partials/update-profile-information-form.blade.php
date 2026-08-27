<section>
    <header>
        <h2 class="text-lg font-medium text-white">
            {{ __('Profile Information') }}
        </h2>

        <p class="mt-1 text-sm text-brand-100/70">
            {{ __('Update your photo, bio, and account details.') }}
        </p>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}" enctype="multipart/form-data" class="mt-6 space-y-6">
        @csrf
        @method('patch')

        <div>
            <x-input-label value="{{ __('Profile Photo') }}" />
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
                            {{ __('Remove current photo') }}
                        </label>
                    @endif
                    <x-input-error class="mt-1" :messages="$errors->get('avatar')" />
                </div>
            </div>
        </div>

        <div>
            <x-input-label for="name" :value="__('Name')" />
            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $user->name)" required autofocus autocomplete="name" />
            <x-input-error class="mt-2" :messages="$errors->get('name')" />
        </div>

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $user->email)" required autocomplete="username" />
            <x-input-error class="mt-2" :messages="$errors->get('email')" />

            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                <div>
                    <p class="text-sm mt-2 text-white">
                        {{ __('Your email address is unverified.') }}

                        <button form="send-verification" class="underline text-sm text-brand-100/70 hover:text-white rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-brand-400">
                            {{ __('Click here to re-send the verification email.') }}
                        </button>
                    </p>

                    @if (session('status') === 'verification-link-sent')
                        <p class="mt-2 font-medium text-sm text-green-300">
                            {{ __('A new verification link has been sent to your email address.') }}
                        </p>
                    @endif
                </div>
            @endif
        </div>

        <div>
            <x-input-label for="phone" :value="__('Phone')" />
            <x-text-input
                id="phone"
                name="phone"
                type="tel"
                class="mt-1 block w-full"
                :value="old('phone', $user->phone ?: $user->employeeRecord?->phone)"
                autocomplete="tel"
            />
            <x-input-error class="mt-2" :messages="$errors->get('phone')" />
        </div>

        <div>
            <x-input-label for="bio" :value="__('Bio')" />
            <textarea
                id="bio"
                name="bio"
                rows="4"
                maxlength="1000"
                class="mt-1 block w-full border-white/15 focus:border-brand-400 focus:ring-brand-400 rounded-md shadow-sm"
                placeholder="{{ __('A short introduction about yourself…') }}"
            >{{ old('bio', $user->bio) }}</textarea>
            <p class="mt-1 text-xs text-brand-100/60">Up to 1000 characters.</p>
            <x-input-error class="mt-2" :messages="$errors->get('bio')" />
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Save') }}</x-primary-button>

            @if (session('status') === 'profile-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm text-brand-100/70"
                >{{ __('Saved.') }}</p>
            @endif
        </div>
    </form>
</section>
