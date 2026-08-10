<x-guest-layout title="Reset password">
    <h2 class="text-2xl sm:text-3xl font-extrabold">Reset your password</h2>
    <p class="mt-2.5 mb-8 text-sm text-brand-100/70">
        {{ __('Tell us the email address on your account and we will send you a link to choose a new password.') }}
    </p>

    <x-auth-session-status class="mb-6" :status="session('status')" />

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <x-auth-field name="email" :label="__('Email')" type="email"
                      :value="old('email')" autocomplete="username" autofocus
                      placeholder="you@chakraproductions.in" />

        <x-auth-button class="w-full mt-6">{{ __('Email reset link') }}</x-auth-button>
    </form>

    <a href="{{ route('login') }}"
       class="mt-7 inline-flex items-center min-h-[44px] text-sm text-brand-300 hover:text-brand-200 transition-colors">
        &larr; {{ __('Back to sign in') }}
    </a>
</x-guest-layout>
