{{-- Date, time, location, status. No crew, no kit, no notes for what's
     already booked -- the controller does not even select those columns for
     the two lists below, so nothing can arrive there by being added to the
     table later. The one place "notes" appears is the request form, which
     writes rather than reads -- what a client asks for going in, not
     staff's own shoot notes coming back out. --}}

<x-app-layout title="Shoots" dark>
    <div class="space-y-6" x-data="{ requesting: {{ $errors->any() ? 'true' : 'false' }} }">

        <div class="animate-rise-in flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-300">{{ $client->name }}</p>
                <h1 class="mt-2 text-3xl sm:text-4xl font-extrabold tracking-tight">Shoots</h1>
                <p class="mt-2 text-sm text-brand-100/70">What is booked, and what has already happened.</p>
            </div>
            <button type="button" @click="requesting = ! requesting"
                    class="shrink-0 inline-flex items-center gap-1.5 min-h-[44px] px-4 rounded-md bg-brand-500 text-white text-sm font-semibold shadow-sm hover:bg-brand-600">
                <span x-show="! requesting">Request a shoot</span>
                <span x-show="requesting" x-cloak>Cancel</span>
            </button>
        </div>

        <div x-show="requesting" x-cloak>
            <x-card padding="md">
                <form method="POST" action="{{ route('client.shoots.request') }}" class="space-y-4">
                    @csrf
                    <div>
                        <x-input-label for="shoot_title" value="What's it for?" />
                        <x-text-input id="shoot_title" name="title" type="text" class="mt-1 w-full"
                                      value="{{ old('title') }}" placeholder="e.g. Product shoot for the new collection" required />
                        <x-input-error :messages="$errors->get('title')" class="mt-2" />
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="shoot_starts_at" value="Preferred date" />
                            <input id="shoot_starts_at" name="starts_at" type="date" required
                                   value="{{ old('starts_at') }}" min="{{ now()->toDateString() }}"
                                   class="mt-1 block w-full rounded-md border-white/15 bg-white/5 text-white text-sm">
                            <x-input-error :messages="$errors->get('starts_at')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="shoot_location" value="Location" />
                            <x-text-input id="shoot_location" name="location" type="text" class="mt-1 w-full"
                                          value="{{ old('location') }}" placeholder="Optional" />
                            <x-input-error :messages="$errors->get('location')" class="mt-2" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="shoot_notes" value="Anything the crew should know" />
                        <x-textarea id="shoot_notes" name="notes" rows="3" class="mt-1 w-full"
                                    placeholder="What you have in mind -- the studio will confirm the date and details with you.">{{ old('notes') }}</x-textarea>
                        <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                    </div>

                    <div class="flex justify-end">
                        <x-primary-button>Send request</x-primary-button>
                    </div>
                </form>
            </x-card>
        </div>

        <section>
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-300 mb-3">Coming up</p>

            @forelse ($upcoming as $shoot)
                <div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-4 sm:p-5 {{ $loop->first ? '' : 'mt-3' }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-semibold text-lg">{{ $shoot->title }}</p>
                            <p class="mt-1 text-sm text-brand-100/70">
                                {{ $shoot->starts_at?->format('l j F, H:i') }}
                                @if ($shoot->ends_at) &ndash; {{ $shoot->ends_at->format('H:i') }} @endif
                            </p>
                            @if ($shoot->location)
                                <p class="mt-1 flex items-center gap-1.5 text-xs text-brand-100/60">
                                    <x-icon name="map-pin" class="w-3.5 h-3.5 shrink-0" />
                                    {{ $shoot->location }}
                                </p>
                            @endif
                        </div>

                        <span class="shrink-0 inline-flex items-center px-2.5 py-1 rounded-full bg-white/10 ring-1 ring-white/20
                                     text-[10px] font-bold uppercase tracking-wide">
                            {{ $shoot->statusLabel() }}
                        </span>
                    </div>
                </div>
            @empty
                <div class="rounded-xl border border-dashed border-white/15 px-6 py-12 text-center">
                    <p class="text-sm text-brand-100/70">Nothing scheduled yet.</p>
                    <p class="mt-1 text-xs text-brand-100/50">Booked shoots will show up here with the date and location.</p>
                </div>
            @endforelse
        </section>

        @if ($past->isNotEmpty())
            <section>
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-300 mb-3">Recently</p>

                <div class="rounded-xl bg-white/5 ring-1 ring-white/10 overflow-hidden">
                    @foreach ($past as $shoot)
                        <div class="flex items-center justify-between gap-3 p-4 {{ $loop->first ? '' : 'border-t border-white/10' }}">
                            <div class="min-w-0">
                                <p class="font-medium truncate">{{ $shoot->title }}</p>
                                <p class="mt-0.5 text-xs text-brand-100/60">
                                    {{ $shoot->starts_at?->format('j M Y') }}
                                    @if ($shoot->location) &middot; {{ $shoot->location }} @endif
                                </p>
                            </div>
                            <span class="shrink-0 text-[10px] font-bold uppercase tracking-wide text-brand-100/50">
                                {{ $shoot->statusLabel() }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-app-layout>
