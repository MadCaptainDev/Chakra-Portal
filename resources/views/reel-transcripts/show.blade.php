{{-- One reel's words: Tanglish clean-up first (what a writer works from), then English, then the raw transcript. --}}
@php
    $media = $t->socialMediaItem;
    $blocks = array_filter([
        'Tanglish' => $t->tanglish,
        'English' => mb_strtolower((string) $t->language) === 'english' ? null : $t->english,
        'As heard'.($t->language ? ' ('.$t->language.')' : '') => $t->transcript,
    ]);
@endphp
<x-app-layout title="Reel transcript">
    <x-slot name="header">
        <x-page-header title="Reel transcript" :subtitle="$media?->socialAccount?->client?->name ?? 'Reel Transcriber'">
            <x-slot name="actions">
                @if ($t->source_url)
                    <x-btn :href="$t->source_url" target="_blank" variant="secondary">Open reel</x-btn>
                @endif
                @if ($t->source_url)
                    <form method="POST" action="{{ route('reel-transcripts.again', $t) }}" x-data="{ busy: false }" @submit="busy = true">
                        @csrf
                        <x-btn type="submit" variant="secondary" icon="refresh" x-bind:disabled="busy"><span x-text="busy ? 'Listening…' : 'Transcribe again'">Transcribe again</span></x-btn>
                    </form>
                @endif
                <x-btn :href="route('reel-transcripts.index')" icon="plus">Another reel</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mx-auto max-w-3xl space-y-4">
        @if (session('error'))
            <p class="rounded-lg bg-red-500/15 px-4 py-3 text-sm text-red-200">{{ session('error') }}</p>
        @endif
        @if ($t->summary)
            <p class="rounded-xl bg-brand-400/10 px-4 py-3 text-sm text-brand-100 ring-1 ring-brand-400/20">{{ $t->summary }}</p>
        @endif

        <p class="text-xs text-brand-100/50">
            {{ $t->language ?: 'Language unknown' }}{{ $t->duration_seconds ? ' · '.$t->duration_seconds.' seconds' : '' }}
            @if ($media)
                · posted {{ $media->posted_at?->format('j M Y') }}
                @if ($media->metricValue('views')) · {{ number_format($media->metricValue('views')) }} views @endif
            @endif
        </p>

        @foreach ($blocks as $label => $text)
            <x-card class="p-4 sm:p-5" x-data="{ copied: false }">
                <div class="flex items-center justify-between gap-2">
                    <x-section-label dark>{{ $label }}</x-section-label>
                    <button type="button" class="text-xs text-brand-300 hover:text-white"
                            @click="navigator.clipboard.writeText($refs.text.innerText); copied = true; setTimeout(() => copied = false, 1500)"
                            x-text="copied ? 'Copied' : 'Copy'"></button>
                </div>
                <div x-ref="text" class="mt-2 whitespace-pre-line text-[15px] leading-relaxed text-white">{{ $text }}</div>
            </x-card>
        @endforeach

        @if ($media?->caption)
            <details class="rounded-xl bg-white/5 px-4 py-3 text-sm ring-1 ring-white/10">
                <summary class="cursor-pointer text-brand-100/70">Caption</summary>
                <p class="mt-2 whitespace-pre-line text-brand-100/80">{{ $media->caption }}</p>
            </details>
        @endif
    </div>
</x-app-layout>
