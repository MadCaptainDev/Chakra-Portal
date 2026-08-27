@php
    use App\Support\BrandBrief;

    /*
     * The client's brand brief, beside the script being written.
     *
     * No new route and no new gate: a writer who is allowed to open this
     * script is allowed to read the brief it was collected for. Requiring the
     * Clients module here would hide the brief from the exact person it exists
     * to help -- a writer has scripts.*, not clients.*.
     *
     * Condensed to BrandBrief::WRITER_KEYS rather than "everything, collapsed".
     * A drawer that has to be read is a drawer that gets closed; these are the
     * answers that change the next sentence somebody types. The full record is
     * one link away for anyone who may see it.
     */
    $brief = $script->client?->brief;
@endphp

@if ($brief?->exists && $brief->answers->isNotEmpty())
    <div x-data="{ brief: false }">
        <button type="button" @click="brief = ! brief"
                class="inline-flex items-center gap-1.5 min-h-[44px] text-sm font-semibold text-brand-300 hover:text-brand-200">
            <span x-show="! brief">Client brief</span>
            <span x-show="brief" x-cloak>Hide brief</span>
            <x-icon name="chevron-right" class="w-4 h-4 transition-transform" ::class="brief && 'rotate-90'" />
        </button>

        <div x-show="brief" x-cloak class="mt-3">
            <x-card class="p-4 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
                    <div class="min-w-0">
                        <h3 class="font-semibold text-white">{{ $script->client->name }}</h3>
                        <p class="text-xs text-brand-100/60 mt-0.5">
                            What they told us before we started writing.
                        </p>
                    </div>

                    {{-- Only for somebody who may open a client record. A writer
                         without the Clients module gets the brief, not a link
                         to a 403. --}}
                    @can('clients.view')
                        <a href="{{ route('clients.show', $script->client) }}"
                           class="text-xs font-semibold uppercase tracking-widest text-brand-300 hover:text-brand-200">
                            See full brief
                        </a>
                    @endcan
                </div>

                @include('clients._brief', [
                    'brief' => $brief,
                    'keys' => BrandBrief::WRITER_KEYS,
                    'compact' => true,
                ])
            </x-card>
        </div>
    </div>
@endif
