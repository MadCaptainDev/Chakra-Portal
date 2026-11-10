@php
    $title = $item?->title ?? $todo?->title ?? 'Video';
    $stages = [
        'plan' => ['Planner', 'text-sky-300'],
        'script' => ['Script', 'text-amber-200'],
        'shoot' => ['Shoot', 'text-red-300'],
        'edit' => ['Editing', 'text-violet-300'],
        'post' => ['Published', 'text-emerald-300'],
    ];
    $reached = $events->pluck('stage')->unique();
    $previousDay = null;
    $first = $events->first()['at'] ?? null;
    $last = $events->last()['at'] ?? null;
@endphp

<x-app-layout :title="$title">
    <x-slot name="header">
        <x-page-header :title="$title" eyebrow="Video timeline"
                       :subtitle="collect([$item?->venture ?? $todo?->venture, $item?->status, $todo?->user?->name])->filter()->implode(' · ')">
            <x-slot name="actions">
                @if ($item?->notion_url)
                    <x-btn :href="$item->notion_url" variant="secondary" target="_blank" rel="noopener">Open in Notion</x-btn>
                @endif
                <x-btn :href="url()->previous() !== url()->current() ? url()->previous() : route('editor-updates')" variant="secondary">Back</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mx-auto max-w-3xl space-y-4">
        <x-card class="p-4 sm:p-5">
            <div class="flex items-start gap-4">
                @if ($video?->thumbnail_path)
                    <img src="{{ asset($video->thumbnail_path) }}" alt="" width="80" class="w-20 shrink-0 rounded-lg bg-white/5 object-cover ring-1 ring-white/10">
                @endif
                <div class="min-w-0 flex-1">
                    {{-- Where it has got to: each stage lights up once something happened in it. --}}
                    <ol class="flex flex-wrap gap-x-1 gap-y-2 text-xs">
                        @foreach ($stages as $key => [$label, $tone])
                            <li class="flex items-center gap-1">
                                <span class="rounded-full px-2.5 py-1 font-medium {{ $reached->contains($key) ? 'bg-white/10 '.$tone : 'bg-white/[0.03] text-brand-100/30' }}">{{ $label }}</span>
                                @unless ($loop->last) <span class="text-brand-100/20">›</span> @endunless
                            </li>
                        @endforeach
                    </ol>
                    <p class="mt-3 text-sm text-brand-100/60">
                        @if ($first && $last && $events->count() > 1)
                            {{ $first->format('j M') }} to {{ $last->format('j M Y') }} · {{ (int) max(1, round($first->diffInDays($last, true))) }} {{ Str::plural('day', (int) max(1, round($first->diffInDays($last, true)))) }} · {{ $events->count() }} steps
                        @elseif ($events->isEmpty())
                            Nothing has been recorded for this video yet.
                        @endif
                    </p>
                    @if ($item?->published_date)
                        <p class="mt-1 text-xs text-brand-100/45">Publish date {{ $item->published_date->format('D j M Y') }}</p>
                    @endif
                </div>
            </div>
        </x-card>

        @if ($events->isNotEmpty())
            <x-card class="p-4 sm:p-5">
                <ol>
                    @foreach ($events as $event)
                        @php
                            $day = $event['at']->toDateString();
                            [$stageLabel, $tone] = $stages[$event['stage']] ?? ['', 'text-white/60'];
                        @endphp
                        @if ($day !== $previousDay)
                            <li class="pb-2 {{ $previousDay ? 'pt-3' : '' }} text-[11px] font-semibold uppercase tracking-wide text-brand-100/40">
                                {{ $event['at']->format('D j M Y') }}
                            </li>
                        @endif
                        @php $previousDay = $day; @endphp
                        <li class="relative flex gap-3 pb-4 last:pb-0">
                            @unless ($loop->last)
                                <span class="absolute left-[7px] top-4 h-full w-px bg-white/10" aria-hidden="true"></span>
                            @endunless
                            <span class="relative mt-1 h-[15px] w-[15px] shrink-0 rounded-full bg-brand-900 ring-2 ring-current {{ $tone }}" aria-hidden="true"></span>
                            <div class="min-w-0 flex-1">
                                <p class="flex flex-wrap items-baseline gap-x-2 text-sm">
                                    <span class="font-medium {{ $tone }}">{{ $event['label'] }}</span>
                                    <span class="tabular-nums text-brand-100/70">{{ $event['at']->format('g:i A') }}</span>
                                    @if ($event['by']) <span class="text-brand-100/50">{{ $event['by'] }}</span> @endif
                                    @if ($event['url'])
                                        <a href="{{ $event['url'] }}" @if (! str_starts_with($event['url'], url('/'))) target="_blank" rel="noopener" @endif
                                           class="text-xs text-sky-300 underline underline-offset-4">Open</a>
                                    @endif
                                </p>
                                @if ($event['note'])
                                    <p class="mt-0.5 break-words text-xs text-brand-100/60">{{ $event['note'] }}</p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            </x-card>
        @endif
    </div>
</x-app-layout>
