@php
    use App\Support\BrandBrief;

    /*
     * Every question the brand brief asks, in one place.
     *
     * Tabbed by group, the same seven groups the client walks. Built-in
     * questions are shown but not editable -- they are the ones the script
     * drawer and the writers depend on, and the screen says so rather than
     * offering a disabled button with no explanation.
     */
    $first = $steps[0]['id'];
@endphp

<x-app-layout title="Brief Questions">
    <x-slot name="header">
        <x-page-header
            title="Brand Brief Questions"
            subtitle="What every client is asked. Add your own to any group — they apply to every brief from the moment you save." />
    </x-slot>

    <div class="max-w-4xl mx-auto" x-data="{ group: '{{ $first }}', adding: false, editing: null }">

        <div class="overflow-x-auto -mx-1 px-1 pb-1 mb-5">
            <x-tab-nav model="group" :tabs="collect($steps)->mapWithKeys(fn ($s) => [
                $s['id'] => [
                    'label' => $s['label'],
                    'count' => count(BrandBrief::questionsFor($s['id'])),
                ],
            ])->all()" />
        </div>

        @foreach ($steps as $step)
            @php
                $core = $step['questions']; // built-ins only; custom ones listed below
                $mine = $custom->get($step['id'], collect());
            @endphp

            <div x-show="group === '{{ $step['id'] }}'" x-cloak class="space-y-4">

                <x-card padding="md">
                    <x-section-heading :title="$step['title']" :subtitle="$step['blurb']" />

                    <ul class="divide-y divide-gray-100">
                        @foreach ($core as $key => $question)
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

                        @foreach ($mine as $question)
                            <li class="py-3 {{ $question->is_active ? '' : 'opacity-50' }}">
                                <div class="flex items-start justify-between gap-4">
                                    <div class="min-w-0">
                                        <p class="text-sm text-gray-900">
                                            {{ $question->label }}
                                            @if ($question->required)
                                                <span class="text-red-600">*</span>
                                            @endif
                                        </p>
                                        <p class="text-xs text-gray-400 mt-0.5">
                                            {{ $types[$question->type] ?? $question->type }}@if ($question->multi), multiple @endif
                                            @if ($question->options)
                                                · {{ count($question->options) }} options
                                            @endif
                                            @unless ($question->is_active)
                                                · archived
                                            @endunless
                                        </p>
                                    </div>

                                    <div class="shrink-0 flex items-center gap-3">
                                        @if ($question->is_active)
                                            <button type="button" @click="editing = editing === {{ $question->id }} ? null : {{ $question->id }}"
                                                    class="text-xs font-semibold uppercase tracking-widest text-brand-600 hover:text-brand-800">
                                                Edit
                                            </button>
                                            <form method="POST" action="{{ route('brief-questions.destroy', $question) }}"
                                                  onsubmit="return confirm('Archive this question? New briefs stop asking it. Answers clients have already given are kept and stay visible on their page.')">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="text-xs font-semibold uppercase tracking-widest text-gray-400 hover:text-red-700">
                                                    Archive
                                                </button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('brief-questions.restore', $question) }}">
                                                @csrf
                                                <button type="submit" class="text-xs font-semibold uppercase tracking-widest text-brand-600 hover:text-brand-800">
                                                    Restore
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </div>

                                {{-- Edit, inline. A separate page for one label
                                     is a page nobody wants to walk to. --}}
                                <div x-show="editing === {{ $question->id }}" x-cloak class="mt-3 pt-3 border-t border-gray-100">
                                    <form method="POST" action="{{ route('brief-questions.update', $question) }}">
                                        @csrf @method('PUT')
                                        @include('brief-questions._fields', [
                                            'steps' => $steps,
                                            'types' => $types,
                                            'question' => $question,
                                        ])
                                        <div class="flex items-center gap-3 mt-3">
                                            <x-primary-button>Save changes</x-primary-button>
                                            <button type="button" @click="editing = null"
                                                    class="text-xs font-semibold uppercase tracking-widest text-gray-500 hover:text-gray-800">
                                                Cancel
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    @if ($core === [] && $mine->isEmpty())
                        <x-empty-state message="No questions in this group yet." />
                    @endif
                </x-card>

                {{-- Add, to this group. The group is carried in a hidden field
                     rather than chosen again, because the tab already said it. --}}
                <x-card padding="md">
                    <form method="POST" action="{{ route('brief-questions.store') }}">
                        @csrf
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
</x-app-layout>
