<x-guest-layout title="Staff sign in">
    <h2 class="text-2xl sm:text-3xl font-extrabold">Sign in</h2>
    <p class="mt-2.5 mb-8 text-sm text-brand-100/70">Use the email address the studio set you up with.</p>

    <x-auth-session-status class="mb-6" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <x-auth-field name="email" :label="__('Email')" type="email"
                      :value="old('email')" autocomplete="username" autofocus
                      placeholder="you@chakraproductions.in" />

        <x-auth-field name="password" :label="__('Password')" type="password"
                      autocomplete="current-password" class="mt-5"
                      placeholder="••••••••" />

        <div class="flex items-center justify-between gap-4 mt-4">
            <label for="remember_me" class="inline-flex items-center gap-2.5 min-h-[44px] text-sm text-brand-100/80 cursor-pointer">
                <input id="remember_me" name="remember" type="checkbox"
                       class="w-4 h-4 rounded border-white/25 bg-white/5 text-brand-400
                              focus:ring-2 focus:ring-brand-400/60 focus:ring-offset-0">
                {{ __('Remember me') }}
            </label>

            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}"
                   class="inline-flex items-center min-h-[44px] text-sm text-brand-300 hover:text-brand-200 transition-colors">
                    {{ __('Forgot password?') }}
                </a>
            @endif
        </div>

        <x-auth-button class="w-full mt-6">{{ __('Sign in') }}</x-auth-button>
    </form>

    <p class="mt-7 text-xs text-brand-100/60">Trouble signing in? Ask the studio to reset your access.</p>
</x-guest-layout>
