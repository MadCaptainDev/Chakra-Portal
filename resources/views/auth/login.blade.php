<x-guest-layout title="Sign in">
  <div data-mascot-scope>
    {{-- The studio's mascot (resources/js/login-mascot.js): it watches the
         caret as you type your email, shuts its eyes for the password, and
         looks surprised when a sign-in fails. Decorative, so hidden from
         screen readers; the poke is a button only for the fun of it. --}}
    <div x-data="loginMascot({ failed: @js($errors->any()) })" class="flex items-end gap-3 mb-5">
        <button type="button" @click="boop()" aria-hidden="true" tabindex="-1"
                class="relative block w-24 h-24 sm:w-28 sm:h-28 shrink-0 cursor-pointer select-none">
            <span x-ref="face" class="relative block w-full h-full" style="transform-origin: 50% 78%">
                @foreach (['directions', 'reactions'] as $sheet)
                    <span class="absolute inset-0 bg-no-repeat [background-size:300%_300%]"
                          style="background-image: url('{{ asset('images/mascot/chakra-'.$sheet.'.webp') }}'); background-position: {{ $sheet === 'directions' ? '50% 50%' : '0% 0%' }}; opacity: {{ $sheet === 'directions' ? 1 : 0 }}"
                          :style="{ backgroundPosition: face.sheet === '{{ $sheet }}' ? face.at : '{{ $sheet === 'directions' ? '50% 50%' : '0% 0%' }}', opacity: face.sheet === '{{ $sheet }}' ? 1 : 0 }"></span>
                @endforeach
            </span>
        </button>
        <p class="pb-2 text-xs text-brand-100/50 leading-snug"
           x-text="typingPassword ? 'Not looking, promise.' : (reaction === 'surprised' ? 'Hmm, try that again?' : (reaction === 'sleepy' ? 'Zzz… type to wake me.' : 'I\'ll keep an eye on it.'))">I'll keep an eye on it.</p>
    </div>

    <h2 class="text-2xl sm:text-3xl font-extrabold">Sign in</h2>
    <p class="mt-2.5 mb-8 text-sm text-brand-100/70">Clients and staff sign in here with the email address the studio set up.</p>

    <x-auth-session-status class="mb-6" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <x-auth-field name="email" :label="__('Email')" type="email"
                      :value="old('email')" autocomplete="username" autofocus
                      placeholder="you@example.com" />

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
  </div>
</x-guest-layout>
