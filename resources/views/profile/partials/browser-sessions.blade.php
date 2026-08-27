@php
    use App\Support\Device;

    /*
     * Where this account is signed in.
     *
     * Sessions carry a one-way handle, never their id -- the id is the cookie
     * value, and a page that prints it hands out a working login to anyone
     * reading over a shoulder. See App\Support\BrowserSessions.
     */
    $icons = [
        Device::PHONE => 'phone',
        Device::TABLET => 'tablet',
        Device::DESKTOP => 'desktop',
    ];

    $others = $devices->where('isCurrent', false);
@endphp

<section>
    <header>
        <h2 class="text-lg font-medium text-white">Where you're signed in</h2>
        <p class="mt-1 text-sm text-brand-100/70">
            Every browser this account is currently signed in on. Don't recognise one? Sign it out.
        </p>
    </header>

    <div class="mt-6 rounded-xl ring-1 ring-white/10 overflow-hidden">
        @forelse ($devices as $device)
            <div class="flex items-start gap-3.5 p-4 {{ $loop->first ? '' : 'border-t border-white/10' }}
                        {{ $device['isCurrent'] ? 'bg-white/5' : '' }}">

                <span @class([
                    'shrink-0 inline-flex items-center justify-center w-10 h-10 rounded-lg',
                    'bg-brand-400/20 text-brand-300' => $device['isCurrent'],
                    'bg-white/10 text-brand-100/60' => ! $device['isCurrent'],
                ])>
                    <x-icon :name="$icons[$device['kind']] ?? 'globe'" class="w-5 h-5" />
                </span>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="font-semibold text-white">{{ $device['label'] }}</p>
                        @if ($device['isCurrent'])
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-brand-400/20
                                         text-[10px] font-bold uppercase tracking-wide text-brand-200">This device</span>
                        @endif
                    </div>

                    <p class="mt-0.5 text-xs text-brand-100/60">
                        {{ $device['ip'] ?: 'No address recorded' }}
                        &middot;
                        {{ $device['isCurrent'] ? 'Active now' : 'Last active '.$device['lastActive']->diffForHumans() }}
                    </p>
                </div>

                @unless ($device['isCurrent'])
                    <form method="POST" action="{{ route('devices.destroy') }}" class="shrink-0"
                          onsubmit="return confirm('Sign out {{ $device['label'] }}?');">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="handle" value="{{ $device['handle'] }}">
                        <button type="submit"
                                class="inline-flex items-center min-h-[36px] px-3 rounded-md border border-white/15
                                       text-[11px] font-semibold uppercase tracking-wider text-brand-100/80
                                       hover:bg-red-400/10 hover:border-red-400/30 hover:text-red-200 transition-colors">
                            Sign out
                        </button>
                    </form>
                @endunless
            </div>
        @empty
            {{-- Only reachable if the row for this very request has aged out,
                 which the lifetime makes near-impossible. Says something rather
                 than showing an empty box. --}}
            <p class="px-4 py-10 text-center text-sm text-brand-100/60">No active sessions recorded.</p>
        @endforelse
    </div>

    @if ($others->isNotEmpty())
        <form method="POST" action="{{ route('devices.destroy-others') }}" class="mt-4"
              onsubmit="return confirm('Sign out of every other device?');">
            @csrf
            @method('DELETE')
            <button type="submit"
                    class="inline-flex items-center justify-center min-h-[44px] px-4 rounded-md bg-red-600
                           text-white text-xs font-semibold uppercase tracking-widest hover:bg-red-700 transition-colors">
                Sign out {{ $others->count() }} other {{ Str::plural('device', $others->count()) }}
            </button>
        </form>
    @endif
</section>
