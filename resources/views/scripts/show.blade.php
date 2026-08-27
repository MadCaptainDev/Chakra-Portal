@php
    /*
    | The read-only script.
    |
    | A real route rather than a client-side toggle, for three reasons: it is
    | what a view-only user gets, it is shareable with a director who is not
    | going to edit anything, and it prints.
    */
    $meta = array_filter([
        'Client' => $script->clientLabel(),
        'Campaign' => $script->campaign,
        'Type' => $script->scriptTypeTerm?->name,
        'Platform' => $script->platformTerm?->name,
        'Language' => $script->languageTerm?->name,
        'Target' => $script->durationLabel(),
        'Writer' => $script->writer?->name,
        'Editor' => $script->editor?->name,
        'Deadline' => $script->due_on?->format('d M Y'),
    ]);
@endphp

<x-app-layout :title="$script->title">
    <x-slot name="header">
        <x-page-header :title="$script->title" eyebrow="Script" :subtitle="$script->clientLabel() ?: 'No client set'">
            <x-slot name="actions">
                @can('scripts.edit')
                    <x-btn :href="route('scripts.edit', $script)" icon="pencil">Edit</x-btn>
                @endcan
                <x-btn :href="route('scripts.index')" variant="secondary">All scripts</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="max-w-3xl mx-auto space-y-5">

        <div class="flex flex-wrap items-center gap-2 print:hidden">
            <x-badge :status="$script->status" />
            @if ($script->due_on)
                <span class="text-xs font-semibold {{ $script->isOverdue() ? 'text-red-300' : 'text-brand-100/60' }}">
                    {{ $script->isOverdue() ? 'Overdue' : 'Due' }} {{ $script->due_on->format('d M') }}
                </span>
            @endif
        </div>

        @if ($meta)
            <x-card class="p-4 sm:p-5 print:shadow-none">
                <dl class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
                    @foreach ($meta as $label => $value)
                        <div>
                            <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-100/50">{{ $label }}</dt>
                            <dd class="mt-0.5 text-white">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-card>
        @endif

        {{-- print:hidden: this goes on set, and the brief is not part of the
             script anybody reads from a stand. --}}
        <div class="print:hidden">
            @include('scripts._brief-drawer')
        </div>

        @if ($script->sections->isEmpty())
            <x-empty-state message="Nothing written yet.">
                @can('scripts.edit')
                    <x-btn :href="route('scripts.edit', $script)" size="sm">Start writing</x-btn>
                @endcan
            </x-empty-state>
        @else
            <x-card class="p-6 sm:p-8 print:shadow-none print:ring-0">
                <div class="space-y-7">
                    @foreach ($script->sections as $section)
                        <section>
                            <h2 class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-300">{{ $section->heading }}</h2>
                            <div class="mt-2 h-px bg-white/10"></div>

                            <div class="script-read mt-3 text-[15px] leading-relaxed text-white">
                                @if ($section->isEmpty())
                                    <p class="text-brand-100/50 italic">Nothing written in this section yet.</p>
                                @else
                                    {{-- Unescaped by necessity: this is the rich text the
                                         writer composed. It is safe because every write
                                         goes through App\Support\Html's allowlist -- see
                                         ScriptSection::setBodyAttribute and the section
                                         update endpoint. Nothing else on this page is
                                         rendered raw. --}}
                                    {!! $section->body !!}
                                @endif
                            </div>
                        </section>
                    @endforeach
                </div>
            </x-card>
        @endif

        <p class="text-xs text-brand-100/50 print:hidden">
            @if ($script->lastEditedBy)
                Last edited by {{ $script->lastEditedBy->name }} {{ $script->last_edited_at?->diffForHumans() }}.
            @endif
            Created {{ $script->created_at->format('d M Y') }}@if ($script->createdBy) by {{ $script->createdBy->name }}@endif.
        </p>
    </div>

    @push('styles')
    <style>
        .script-read ul { list-style: disc; padding-left: 1.4rem; margin: .4rem 0; }
        .script-read ol { list-style: decimal; padding-left: 1.4rem; margin: .4rem 0; }
        .script-read p { margin: 0 0 .6rem; }
        .script-read a { color: #8ACCE0; text-decoration: underline; }
        @media print {
            .script-read { font-size: 12pt; }
        }
    </style>
    @endpush
</x-app-layout>
