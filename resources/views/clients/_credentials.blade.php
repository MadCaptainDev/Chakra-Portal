@php
    use App\Models\ClientCredential;

    /*
     * The logins the studio holds for this client.
     *
     * The password is never in this page. Reveal fetches it over one request,
     * which writes down who asked; putting it in the HTML would put it in the
     * browser cache, the back button and every screenshot of the screen.
     */
    $kindTone = [
        ClientCredential::KIND_INSTAGRAM => 'bg-pink-400/15 text-pink-200',
        ClientCredential::KIND_YOUTUBE => 'bg-red-400/15 text-red-200',
        ClientCredential::KIND_GOOGLE => 'bg-blue-400/15 text-blue-200',
        ClientCredential::KIND_FACEBOOK => 'bg-indigo-400/15 text-indigo-200',
        ClientCredential::KIND_OTHER => 'bg-white/10 text-brand-100/70',
    ];
@endphp

<x-card class="p-4 sm:p-6 border border-white/10" x-data="{ adding: {{ $errors->has('kind') || $errors->has('secret') ? 'true' : 'false' }} }">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 class="font-semibold text-white">Account logins</h3>
            <p class="mt-1 text-sm text-brand-100/70">
                Instagram, YouTube and Google details the studio holds for {{ $client->name }}.
                Encrypted, and every time one is revealed it is written down.
            </p>
        </div>
        <span class="shrink-0 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-white/10 text-brand-100/70 text-[10px] font-bold uppercase tracking-wide">
            <x-icon name="eye" class="w-3.5 h-3.5" />
            Views logged
        </span>
    </div>

    <div class="mt-5 space-y-3">
        @forelse ($credentials as $credential)
            <div class="rounded-xl ring-1 ring-white/10 p-4"
                 x-data="{
                     open: false,
                     editing: false,
                     loading: false,
                     error: '',
                     secret: '',
                     username: '',
                     notes: '',
                     async reveal() {
                         if (this.open) { this.hide(); return; }
                         this.loading = true; this.error = '';
                         try {
                             const response = await fetch('{{ route('clients.credentials.reveal', [$client, $credential]) }}', {
                                 method: 'POST',
                                 headers: {
                                     'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                     'Accept': 'application/json',
                                 },
                             });
                             if (! response.ok) throw new Error(response.status);
                             const data = await response.json();
                             this.secret = data.secret ?? '';
                             this.username = data.username ?? '';
                             this.notes = data.notes ?? '';
                             this.open = true;
                         } catch (e) {
                             this.error = 'Could not read that — reload and try again.';
                         } finally {
                             this.loading = false;
                         }
                     },
                     hide() {
                         /* Cleared out of memory as well as off the screen. */
                         this.open = false; this.secret = ''; this.notes = '';
                     },
                 }"
                 @keydown.escape.window="hide()">

                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide {{ $kindTone[$credential->kind] ?? $kindTone['other'] }}">
                                {{ $credential->kindLabel() }}
                            </span>
                            @if ($credential->label)
                                <p class="font-semibold text-white">{{ $credential->label }}</p>
                            @endif
                        </div>

                        <p class="mt-1.5 text-sm text-brand-100/80 font-mono break-all">{{ $credential->username ?: '—' }}</p>

                        <p class="mt-1 text-xs text-brand-100/60">
                            @if ($credential->url)
                                <a href="{{ $credential->url }}" target="_blank" rel="noopener noreferrer"
                                   class="text-brand-500 hover:text-brand-300">Open account</a>
                                &middot;
                            @endif
                            @if ($credential->views->isNotEmpty())
                                Last seen by {{ $credential->views->first()->user?->name ?? 'someone' }}
                                {{ $credential->views->first()->viewed_at->diffForHumans() }}
                                &middot; {{ $credential->views->count() }} {{ Str::plural('view', $credential->views->count()) }}
                            @else
                                Never revealed
                            @endif
                        </p>
                    </div>

                    <div class="shrink-0 flex items-center gap-2">
                        @if ($credential->hasSecret())
                            <button type="button" @click="reveal()" :disabled="loading"
                                    class="inline-flex items-center gap-1.5 min-h-[36px] px-3 rounded-md bg-brand-400 text-brand-900
                                           text-[11px] font-semibold uppercase tracking-wider hover:bg-brand-500 transition disabled:opacity-50">
                                <x-icon name="eye" class="w-3.5 h-3.5" />
                                <span x-text="loading ? 'Reading…' : (open ? 'Hide' : 'Reveal')">Reveal</span>
                            </button>
                        @endif
                        <button type="button" @click="editing = ! editing"
                                class="inline-flex items-center min-h-[36px] px-3 rounded-md border border-white/15 text-brand-100/80
                                       text-[11px] font-semibold uppercase tracking-wider hover:bg-white/[0.09] transition">
                            Edit
                        </button>
                    </div>
                </div>

                <p x-show="error" x-cloak x-text="error" class="mt-2 text-xs text-red-200"></p>

                {{-- The revealed value. Selectable so it can be copied, and
                     gone again the moment Hide or Escape is pressed. --}}
                <div x-show="open" x-cloak class="mt-3 rounded-lg bg-amber-400/10 ring-1 ring-amber-400/30 p-3">
                    <p class="text-[10px] font-bold uppercase tracking-wide text-amber-200">Password</p>
                    <code class="mt-1 block w-full overflow-x-auto text-sm text-white select-all" x-text="secret"></code>

                    <template x-if="notes">
                        <div class="mt-3">
                            <p class="text-[10px] font-bold uppercase tracking-wide text-amber-200">Notes</p>
                            <p class="mt-1 text-xs text-brand-100/80 whitespace-pre-line" x-text="notes"></p>
                        </div>
                    </template>

                    <button type="button" @click="hide()" class="mt-3 text-xs font-semibold text-amber-200 hover:text-amber-200">
                        Hide again
                    </button>
                </div>

                {{-- Edit. The password field is blank and staying blank leaves
                     the stored one alone -- see ClientCredentialController. --}}
                <form x-show="editing" x-cloak method="POST"
                      action="{{ route('clients.credentials.update', [$client, $credential]) }}" class="mt-4 space-y-3">
                    @csrf
                    @method('PUT')
                    @include('clients._credential-fields', ['credential' => $credential])

                    <div class="flex flex-wrap items-center gap-3">
                        <x-primary-button>Save changes</x-primary-button>
                        <button type="button" @click="editing = false" class="text-sm text-brand-100/70 hover:text-white">Cancel</button>

                        <button type="submit" form="delete-credential-{{ $credential->id }}"
                                class="ml-auto text-xs font-semibold text-red-300 hover:text-red-200">
                            Delete
                        </button>
                    </div>
                </form>

                <form id="delete-credential-{{ $credential->id }}" method="POST"
                      action="{{ route('clients.credentials.destroy', [$client, $credential]) }}" class="hidden"
                      onsubmit="return confirm('Delete this login? It cannot be recovered.');">
                    @csrf
                    @method('DELETE')
                </form>
            </div>
        @empty
            <p class="py-6 text-center text-sm text-brand-100/60">No account logins stored yet.</p>
        @endforelse
    </div>

    <div class="mt-4" x-show="! adding">
        <x-primary-button type="button" @click="adding = true">
            <span class="inline-flex items-center gap-1.5"><x-icon name="plus" class="w-4 h-4" /> Add a login</span>
        </x-primary-button>
    </div>

    <form method="POST" action="{{ route('clients.credentials.store', $client) }}" class="mt-4 space-y-3"
          x-show="adding" x-cloak>
        @csrf
        @include('clients._credential-fields', ['credential' => null])

        <div class="flex items-center gap-3">
            <x-primary-button>Save login</x-primary-button>
            <button type="button" @click="adding = false" class="text-sm text-brand-100/70 hover:text-white">Cancel</button>
        </div>
    </form>

    <p class="mt-4 text-xs text-brand-100/60">
        Stored encrypted, not hashed — they have to be readable to be typed into Instagram. Prefer an
        app-specific password over the client's main Google password, and keep two-factor on their account.
    </p>
</x-card>
