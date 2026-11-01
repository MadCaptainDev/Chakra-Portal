{{-- Reel Transcriber: paste a reel link or upload the video (ReelTranscriptController). --}}
<x-app-layout title="Reel Transcriber">
    <x-slot name="header">
        <x-page-header title="Reel Transcriber" subtitle="What a reel says — in Tamil, English and clean Tanglish">
            <x-slot name="actions">
                <x-btn :href="route('scripts.index')" variant="secondary">All scripts</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mx-auto max-w-3xl space-y-5">
        @if (session('error'))
            <p class="rounded-lg bg-red-500/15 px-4 py-3 text-sm text-red-200">{{ session('error') }}</p>
        @endif

        <x-card class="p-4 sm:p-5">
            <form method="POST" action="{{ route('reel-transcripts.store') }}" enctype="multipart/form-data"
                  x-data="{ busy: false, file: '' }" @submit="busy = true" class="space-y-3">
                @csrf
                <label class="block text-sm font-semibold text-white" for="reel-url">Reel link</label>
                <input id="reel-url" type="url" name="url" value="{{ old('url') }}" placeholder="https://www.instagram.com/reel/…" :disabled="!!file"
                       class="w-full min-h-[44px] rounded-lg border-white/15 bg-white/5 text-sm text-white placeholder:text-brand-100/40 focus:border-brand-400 focus:ring-brand-400 disabled:opacity-40">
                <x-input-error :messages="$errors->get('url')" />

                <div class="flex items-center gap-3 text-xs text-brand-100/50"><span class="h-px flex-1 bg-white/10"></span>or upload the video<span class="h-px flex-1 bg-white/10"></span></div>
                <input type="file" name="video" accept="video/*,audio/*" @change="file = $event.target.files[0]?.name || ''"
                       class="block w-full text-sm text-brand-100/70 file:mr-3 file:rounded-md file:border-0 file:bg-white/10 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-white/15">
                <x-input-error :messages="$errors->get('video')" />
                <p class="text-xs text-brand-100/50">
                    Links work for our clients' own reels (not ones with licensed music — Instagram keeps those) and direct video links.
                    For anyone else's reel, download it and upload it here. Up to {{ $maxMb }} MB.
                </p>

                <button type="submit" :disabled="busy"
                        class="inline-flex min-h-[44px] items-center gap-2 rounded-lg bg-brand-400 px-5 text-sm font-bold text-brand-900 hover:bg-brand-300 disabled:opacity-60">
                    <span x-show="busy" class="h-4 w-4 animate-spin rounded-full border-2 border-brand-900/30 border-t-brand-900"></span>
                    <span x-text="busy ? 'Listening… (about 20 seconds)' : 'Transcribe'"></span>
                </button>
            </form>
        </x-card>

        @if ($recent->isNotEmpty())
            <x-card class="p-4 sm:p-5">
                <x-section-label dark>Recent</x-section-label>
                <ul class="mt-3 divide-y divide-white/5">
                    @foreach ($recent as $t)
                        <li>
                            <a href="{{ route('reel-transcripts.show', $t) }}" class="flex items-center gap-3 py-2.5 hover:bg-white/[0.03]">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-semibold text-white">{{ $t->summary ?: Str::limit($t->english ?: $t->transcript, 90) }}</p>
                                    <p class="truncate text-xs text-brand-100/50">
                                        {{ $t->socialMediaItem?->socialAccount?->client?->name ?? ($t->source_url ? parse_url($t->source_url, PHP_URL_HOST) : 'Uploaded file') }}
                                        · {{ $t->language ?: '—' }}{{ $t->duration_seconds ? ' · '.$t->duration_seconds.'s' : '' }}
                                        · {{ $t->createdBy?->name ?? 'MCP' }}, {{ $t->created_at->diffForHumans() }}
                                    </p>
                                </div>
                                <span class="shrink-0 text-xs text-brand-300">Open →</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </x-card>
        @endif
    </div>
</x-app-layout>
