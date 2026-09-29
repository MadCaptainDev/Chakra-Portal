@php
    /*
     * The iPhone home-screen widget.
     *
     * iOS only lets App Store apps put widgets on the home screen, so this
     * goes through Scriptable (free): the portal hands over a ready-to-paste
     * script with a read-only key already inside it, and Scriptable draws the
     * widget from GET /api/widget/today. The script lives in
     * resources/widget/chakra-widget.js.
     */
    $plain = session('widget_token_plain');
    $script = $plain
        ? strtr(file_get_contents(resource_path('widget/chakra-widget.js')), [
            '__API_URL__' => route('api.widget.today'),
            '__TOKEN__' => $plain,
        ])
        : null;
@endphp

<section id="phone-widget" class="scroll-mt-20"
         x-data="{ adding: {{ $errors->widgetToken->has('name') ? 'true' : 'false' }} }">
    <header>
        <h2 class="text-lg font-medium text-white">iPhone widget</h2>
        <p class="mt-1 text-sm text-brand-100/70">
            Today at a glance on your home screen: hours logged, today's shoots, open to-dos{{ $user->isAdmin() ? ' and the Reel Planner' : '' }}.
            Uses the free <strong class="text-white">Scriptable</strong> app. The key inside can only read this summary — it can't change anything.
        </p>
    </header>

    @if ($script)
        <div class="mt-6 rounded-xl bg-white/5 ring-1 ring-brand-300 p-4" x-data="{ copied: false }">
            <p class="text-xs font-semibold uppercase tracking-wider text-brand-200">Your widget script</p>
            <p class="mt-1 text-xs text-brand-100/70">Copy it now. The key in it is not stored in a readable form and cannot be shown again.</p>

            <button type="button"
                    @click="navigator.clipboard.writeText($refs.script.textContent); copied = true; setTimeout(() => copied = false, 2500)"
                    class="mt-3 inline-flex items-center justify-center w-full min-h-[44px] px-4 rounded-md bg-brand-400 text-brand-900
                           text-xs font-semibold uppercase tracking-widest hover:bg-brand-500 transition-colors">
                <span x-show="! copied">Copy widget script</span>
                <span x-show="copied" x-cloak>Copied — now open Scriptable</span>
            </button>

            <details class="mt-3">
                <summary class="cursor-pointer text-xs text-brand-100/60">Show the script</summary>
                <pre x-ref="script" class="mt-2 max-h-64 overflow-auto rounded-md bg-black/30 p-3 text-[10px] leading-snug text-brand-100 ring-1 ring-white/10 select-all">{{ $script }}</pre>
            </details>
        </div>
    @endif

    <ol class="mt-6 space-y-2 text-sm text-brand-100/80 list-decimal list-inside">
        <li>Install <a href="https://apps.apple.com/app/scriptable/id1405459188" target="_blank" rel="noopener" class="text-brand-300 underline">Scriptable</a> from the App Store (free).</li>
        <li>Tap <strong class="text-white">Make widget script</strong> below, then <strong class="text-white">Copy widget script</strong> — do this on the iPhone itself.</li>
        <li>In Scriptable tap <strong class="text-white">+</strong>, paste, name it <em>Chakra</em>, and tap <strong class="text-white">Done</strong>.</li>
        <li>On the home screen, press and hold → <strong class="text-white">Edit</strong> → <strong class="text-white">Add Widget</strong> → Scriptable → pick a size.</li>
        <li>Press and hold the new widget → <strong class="text-white">Edit Widget</strong> → Script: <em>Chakra</em>.</li>
    </ol>

    @if ($widgetTokens->isNotEmpty())
        <div class="mt-6 rounded-xl ring-1 ring-white/10 overflow-hidden">
            @foreach ($widgetTokens as $token)
                <div class="flex items-start gap-3.5 p-4 {{ $loop->first ? '' : 'border-t border-white/10' }}">
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-white truncate">{{ $token->name }}</p>
                        <p class="mt-0.5 text-xs text-brand-100/60">
                            Created {{ $token->created_at->format('j M Y') }}
                            &middot;
                            {{ $token->last_used_at ? 'last updated '.$token->last_used_at->diffForHumans() : 'never used' }}
                        </p>
                    </div>

                    <form method="POST" action="{{ route('widget-tokens.destroy', $token) }}" class="shrink-0"
                          onsubmit="return confirm('Revoke &quot;{{ $token->name }}&quot;? That widget stops updating immediately.');">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                                class="inline-flex items-center min-h-[36px] px-3 rounded-md border border-white/15
                                       text-[11px] font-semibold uppercase tracking-wider text-brand-100/80
                                       hover:bg-red-400/10 hover:border-red-400/30 hover:text-red-200 transition-colors">
                            Revoke
                        </button>
                    </form>
                </div>
            @endforeach
        </div>
    @endif

    <div class="mt-4" x-show="! adding">
        <button type="button" @click="adding = true"
                class="inline-flex items-center gap-1.5 min-h-[44px] px-4 rounded-md bg-brand-400 text-brand-900
                       text-xs font-semibold uppercase tracking-widest hover:bg-brand-500 transition-colors">
            <x-icon name="plus" class="w-4 h-4" />
            Make widget script
        </button>
    </div>

    <form method="POST" action="{{ route('widget-tokens.store') }}" class="mt-4" x-show="adding" x-cloak>
        @csrf
        <x-input-label for="widget_token_name" value="Which phone?" />
        <x-text-input id="widget_token_name" name="name" type="text" class="mt-1 block w-full"
                      value="My iPhone" required />
        <x-input-error :messages="$errors->widgetToken->get('name')" class="mt-2" />

        <div class="mt-3 flex items-center gap-3">
            <x-primary-button>Make script</x-primary-button>
            <button type="button" @click="adding = false" class="text-sm text-brand-100/70 hover:text-white">Cancel</button>
        </div>
    </form>
</section>
