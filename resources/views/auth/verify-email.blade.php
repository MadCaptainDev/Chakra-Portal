<x-guest-layout title="Verify your email">
    <h2 class="text-2xl sm:text-3xl font-extrabold">Verify your email</h2>
    <p class="mt-2.5 mb-8 text-sm text-brand-100/70">
        {{ __('We have emailed you a verification link. Click it to finish setting up your account — and if it has not arrived, we will happily send another.') }}
    </p>

    @if (session('status') == 'verification-link-sent')
        <div class="mb-6 px-3.5 py-3 rounded-md bg-brand-400/15 border border-brand-400/40 text-sm text-brand-200" role="status">
            {{ __('A new verification link has been sent to your email address.') }}
        </div>
    @endif

    <form method="POST" action="{{ route('verification.send') }}">
        @csrf
        <x-auth-button class="w-full">{{ __('Resend verification email') }}</x-auth-button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-4">
        @csrf
        <button type="submit"
                class="inline-flex items-center min-h-[44px] text-sm text-brand-300 hover:text-brand-200 transition-colors">
            {{ __('Sign out') }}
        </button>
    </form>
</x-guest-layout>
