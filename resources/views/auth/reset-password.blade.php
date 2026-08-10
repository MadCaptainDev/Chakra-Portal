<x-guest-layout title="Choose a new password">
    <h2 class="text-2xl sm:text-3xl font-extrabold">Choose a new password</h2>
    <p class="mt-2.5 mb-8 text-sm text-brand-100/70">Pick something you have not used here before.</p>

    <form method="POST" action="{{ route('password.store') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <x-auth-field name="email" :label="__('Email')" type="email"
                      :value="old('email', $request->email)" autocomplete="username" autofocus />

        <x-auth-field name="password" :label="__('New password')" type="password"
                      autocomplete="new-password" class="mt-5" />

        <x-auth-field name="password_confirmation" :label="__('Confirm password')" type="password"
                      autocomplete="new-password" class="mt-5" />

        <x-auth-button class="w-full mt-6">{{ __('Reset password') }}</x-auth-button>
    </form>
</x-guest-layout>
