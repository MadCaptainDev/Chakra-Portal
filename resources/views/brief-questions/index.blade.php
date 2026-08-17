@php
    use App\Support\BrandBrief;

    /*
     * Every question the brand brief asks, in one place.
     *
     * Two modes, chosen by the picker. "Every client" manages the shared set,
     * tabbed by the seven groups the client walks. Picking a client manages
     * only that client's own questions -- they still get all the shared ones,
     * which the screen says rather than listing them again read-only.
     *
     * Split rather than combined because they are two different jobs, and a
     * single list mixing them is how a question meant for one client ends up
     * on everybody's brief.
     */
    $first = $steps[0]['id'];
@endphp

<x-app-layout title="Brief Questions">
    <x-slot name="header">
        <x-page-header
            title="Brand Brief Questions"
            :subtitle="$client
                ? 'Questions only '.$client->name.' is asked, on top of the shared set.'
                : 'What every client is asked. Add your own to any group — they apply to every brief from the moment you save.'" />
    </x-slot>

    <div class="max-w-4xl mx-auto space-y-5">

        {{-- The scope switcher. A plain GET form: the mode belongs in the URL
             so it survives a save, a refresh and a bookmark. --}}
        <x-card padding="md">
            <form method="GET" action="{{ route('brief-questions.index') }}"
                  class="flex flex-wrap items-end gap-3">
                <div class="min-w-[220px]">
                    <x-input-label for="client" value="Editing questions for" />
                    <select name="client" id="client" onchange="this.form.submit()"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Every client (shared questions)</option>
                        @foreach ($clients as $option)
                            <option value="{{ $option->id }}" @selected($client?->id === $option->id)>
                                {{ $option->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <noscript>
                    <x-primary-button class="mb-1">Go</x-primary-button>
                </noscript>

                @if ($client)
                    <p class="text-xs text-gray-500 mb-2 flex-1 min-w-[240px]">
                        {{ $client->name }} is asked every shared question as well. These appear as an extra group at
                        the end of their brief, and no other client ever sees them.
                    </p>
                @endif
            </form>
        </x-card>

        @if ($client)
            {{-- ——— One client's own questions ——— --}}
            @php $label = $mine->firstWhere('group_label')?->group_label ?: 'Your craft'; @endphp

            <x-card padding="md">
                <x-section-heading
                    :title="'“'.$label.'” — '.$client->name"
                    :subtitle="$mine->isEmpty()
                        ? 'Nothing yet. Anything added below is asked of this client and nobody else.'
                        : $mine->where('is_active', true)->count().' question(s) only this client is asked.'" />

                @if ($mine->isEmpty())
                    <x-empty-state message="No questions for this client yet." />
                @else
                    <ul class="divide-y divide-gray-100">
                        @foreach ($mine as $question)
                            @include('brief-questions._row', [
                                'question' => $question,
                                'types' => $types,
                                'steps' => $steps,
                                'client' => $client,
                            ])
                        @endforeach
                    </ul>
                @endif
            </x-card>

            <x-card padding="md">
                <form method="POST" action="{{ route('brief-questions.store', ['client' => $client->id]) }}">
                    @csrf
                    <input type="hidden" name="client_id" value="{{ $client->id }}">

                    <x-section-heading
                        :title="'Add a question for '.$client->name"
                        subtitle="Only this client's brief asks it. Their brief is not reopened if it has already been sent in." />

                    <div class="mb-3">
                        <x-input-label for="group_label" value="Group heading" />
                        <x-text-input name="group_label" type="text" class="mt-1 w-full sm:max-w-xs"
                                      value="{{ old('group_label', $label) }}" />
                        <p class="text-xs text-gray-500 mt-1">The tab these appear under on their brief.</p>
                    </div>

                    @include('brief-questions._fields', [
                        'steps' => $steps,
                        'types' => $types,
                        'question' => null,
                        'stepId' => null,
                    ])

                    <div class="mt-3">
                        <x-primary-button>Add question</x-primary-button>
                    </div>
                </form>
            </x-card>
        @else
            {{-- ——— The shared set, tabbed by group ——— --}}
            <div x-data="{ group: '{{ $first }}', editing: null }">
                <div class="overflow-x-auto -mx-1 px-1 pb-1 mb-5">
                    <x-tab-nav model="group" :tabs="collect($steps)->mapWithKeys(fn ($s) => [
                        $s['id'] => [
                            'label' => $s['label'],
                            'count' => count(BrandBrief::questionsFor($s['id'])),
                        ],
                    ])->all()" />
                </div>

                @foreach ($steps as $step)
                    @php $mineHere = $shared->get($step['id'], collect()); @endphp

                    <div x-show="group === '{{ $step['id'] }}'" x-cloak class="space-y-4">
                        <x-card padding="md">
                            <x-section-heading :title="$step['title']" :subtitle="$step['blurb']" />

                            <ul class="divide-y divide-gray-100">
                                @foreach ($step['questions'] as $key => $question)
                                    <li class="py-3 flex items-start justify-between gap-4">
                                        <div class="min-w-0">
                                            <p class="text-sm text-gray-900">
                                                {{ $question['label'] }}
                                                @if ($question['required'] ?? false)
                                                    <span class="text-red-600">*</span>
                                                @endif
                                            </p>
                                            <p class="text-xs text-gray-400 mt-0.5">
                                                {{ ucfirst($question['type']) }}@if ($question['multi'] ?? false), multiple @endif
                                                @if (isset($question['showIf']))
                                                    · only when “{{ $question['showIf'][1] }}”
                                                @endif
                                            </p>
                                        </div>
                                        <span class="shrink-0 text-[11px] font-semibold uppercase tracking-wider text-gray-400">Built in</span>
                                    </li>
                                @endforeach

                                @foreach ($mineHere as $question)
                                    @include('brief-questions._row', [
                                        'question' => $question,
                                        'types' => $types,
                                        'steps' => $steps,
                                        'client' => null,
                                    ])
                                @endforeach
                            </ul>
                        </x-card>

                        <x-card padding="md">
                            <form method="POST" action="{{ route('brief-questions.store') }}">
                                @csrf
                                <input type="hidden" name="step_id" value="{{ $step['id'] }}">

                                <x-section-heading
                                    title="Add a question to {{ $step['label'] }}"
                                    subtitle="Every client's brief asks it from the moment you save. Briefs already sent in are not reopened." />

                                @include('brief-questions._fields', [
                                    'steps' => $steps,
                                    'types' => $types,
                                    'question' => null,
                                    'stepId' => $step['id'],
                                ])

                                <div class="mt-3">
                                    <x-primary-button>Add question</x-primary-button>
                                </div>
                            </form>
                        </x-card>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-app-layout>
