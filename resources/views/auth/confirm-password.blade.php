<x-guest-layout title="Confirm password">
    <h2 class="text-2xl sm:text-3xl font-extrabold">Confirm your password</h2>
    <p class="mt-2.5 mb-8 text-sm text-brand-100/70">
        {{ __('This is a secure area. Please confirm your password before continuing.') }}
    </p>

    <form method="POST" action="{{ route('password.confirm') }}">
        @csrf

        <x-auth-field name="password" :label="__('Password')" type="password"
                      autocomplete="current-password" autofocus />

        <x-auth-button class="w-full mt-6">{{ __('Confirm') }}</x-auth-button>
    </form>
</x-guest-layout>
