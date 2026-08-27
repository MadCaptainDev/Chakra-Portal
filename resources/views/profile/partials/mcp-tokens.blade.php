@php
    /*
     * Connecting Claude to the portal.
     *
     * The command is shown filled in and ready to paste, because the alternative
     * is a paragraph of prose describing a command, which people then get wrong.
     */
    $plain = session('mcp_token_plain');
@endphp

<section x-data="{ adding: {{ $errors->has('name') ? 'true' : 'false' }} }">
    <header>
        <h2 class="text-lg font-medium text-white">Connect Claude</h2>
        <p class="mt-1 text-sm text-brand-100/70">
            Lets Claude read and update your timesheet, to-dos{{ $user->isAdmin() ? ', shoots and scripts' : '' }}
            from a chat. A token acts as you and can never reach further than you can.
        </p>
    </header>

    {{-- The one and only sighting of the token. --}}
    @if ($plain)
        <div class="mt-6 rounded-xl bg-white/5 ring-1 ring-brand-300 p-4">
            <p class="text-xs font-semibold uppercase tracking-wider text-brand-200">Your new token</p>
            <p class="mt-1 text-xs text-brand-100/70">Copy it now. It is not stored in a readable form and cannot be shown again.</p>

            <div class="mt-3" x-data="{ copied: false }">
                <code class="block w-full overflow-x-auto rounded-md bg-white/5 px-3 py-2.5 text-xs
                             text-white ring-1 ring-white/10 select-all">{{ $plain }}</code>

                <button type="button"
                        @click="navigator.clipboard.writeText($el.previousElementSibling.textContent.trim());
                                copied = true; setTimeout(() => copied = false, 2000)"
                        class="mt-2 inline-flex items-center min-h-[36px] px-3 rounded-md bg-brand-400 text-brand-900
                               text-[11px] font-semibold uppercase tracking-wider hover:bg-brand-500 transition-colors">
                    <span x-show="! copied">Copy token</span>
                    <span x-show="copied" x-cloak>Copied</span>
                </button>
            </div>

            <p class="mt-4 text-xs font-semibold uppercase tracking-wider text-brand-200">Then run this once</p>
            <code class="mt-2 block w-full overflow-x-auto rounded-md bg-white/5 px-3 py-2.5 text-xs
                         text-white ring-1 ring-white/10 select-all">claude mcp add --transport http chakra {{ route('mcp') }} --header "Authorization: Bearer {{ $plain }}"</code>
        </div>
    @endif

    <div class="mt-6 rounded-xl ring-1 ring-white/10 overflow-hidden">
        @forelse ($mcpTokens as $token)
            <div class="flex items-start gap-3.5 p-4 {{ $loop->first ? '' : 'border-t border-white/10' }}">
                <span class="shrink-0 inline-flex items-center justify-center w-10 h-10 rounded-lg bg-white/10 text-brand-100/60">
                    <x-icon name="sparkles" class="w-5 h-5" />
                </span>

                <div class="min-w-0 flex-1">
                    <p class="font-semibold text-white truncate">{{ $token->name }}</p>
                    <p class="mt-0.5 text-xs text-brand-100/60">
                        Created {{ $token->created_at->format('j M Y') }}
                        &middot;
                        {{ $token->last_used_at ? 'last used '.$token->last_used_at->diffForHumans() : 'never used' }}
                    </p>
                </div>

                <form method="POST" action="{{ route('mcp-tokens.destroy', $token) }}" class="shrink-0"
                      onsubmit="return confirm('Revoke &quot;{{ $token->name }}&quot;? Anything using it stops working immediately.');">
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
        @empty
            <p class="px-4 py-10 text-center text-sm text-brand-100/60">No tokens yet.</p>
        @endforelse
    </div>

    <div class="mt-4" x-show="! adding">
        <button type="button" @click="adding = true"
                class="inline-flex items-center gap-1.5 min-h-[44px] px-4 rounded-md bg-brand-400 text-brand-900
                       text-xs font-semibold uppercase tracking-widest hover:bg-brand-500 transition-colors">
            <x-icon name="plus" class="w-4 h-4" />
            New token
        </button>
    </div>

    <form method="POST" action="{{ route('mcp-tokens.store') }}" class="mt-4" x-show="adding" x-cloak>
        @csrf
        <x-input-label for="mcp_token_name" value="What is it for?" />
        <x-text-input id="mcp_token_name" name="name" type="text" class="mt-1 block w-full"
                      value="{{ old('name') }}" placeholder="e.g. My laptop" required />
        <x-input-error :messages="$errors->get('name')" class="mt-2" />

        <div class="mt-3 flex items-center gap-3">
            <x-primary-button>Create token</x-primary-button>
            <button type="button" @click="adding = false" class="text-sm text-brand-100/70 hover:text-white">Cancel</button>
        </div>
    </form>
</section>
