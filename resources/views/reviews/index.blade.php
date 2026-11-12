{{-- Videos waiting for a verdict, oldest first, and the ones decided lately. --}}
@php
    $verdictTone = ['good_to_go' => 'bg-emerald-400/15 text-emerald-200', 'minor_fixes' => 'bg-amber-400/15 text-amber-200', 'needs_fixes' => 'bg-red-400/15 text-red-200'];
@endphp
<x-app-layout title="Video review" dark>
    <div class="mx-auto max-w-6xl space-y-8 pb-16">
        <div>
            <h1 class="text-[28px] font-extrabold tracking-tight text-white">Video review</h1>
            <p class="text-sm text-brand-100/60">{{ $waiting->count() }} waiting · editors upload from My Editing, Gemini checks each one, you decide.</p>
        </div>

        <section>
            <h2 class="mb-3 text-[17px] font-semibold text-white">Waiting for you</h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @forelse ($waiting as $v)
                    @include('reviews._tile', ['v' => $v])
                @empty
                    <p class="rounded-2xl border border-emerald-400/25 bg-emerald-400/[0.06] px-5 py-6 text-sm text-emerald-100/85 sm:col-span-2 lg:col-span-3">Nothing waiting. New uploads appear here and on the bell.</p>
                @endforelse
            </div>
        </section>

        @if ($done->isNotEmpty())
            <section>
                <h2 class="mb-3 text-[17px] font-semibold text-white">Decided lately</h2>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($done as $v)
                        @include('reviews._tile', ['v' => $v])
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-app-layout>
