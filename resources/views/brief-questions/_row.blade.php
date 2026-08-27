@php
    /*
     * One studio-added question in the list, with its inline editor.
     *
     * Shared by both modes of the screen, so a question edits the same way
     * whether it belongs to everybody or to one client.
     *
     * $question  BriefQuestion
     * $client    Client|null   the scope being edited, kept on the form so a
     *                          save returns to the same list
     */
@endphp

<li class="py-3 {{ $question->is_active ? '' : 'opacity-50' }}"
    x-data="{ open: false }">
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="text-sm text-white">
                {{ $question->label }}
                @if ($question->required)
                    <span class="text-red-300">*</span>
                @endif
            </p>
            <p class="text-xs text-brand-100/50 mt-0.5">
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
                <button type="button" @click="open = ! open"
                        class="text-xs font-semibold uppercase tracking-widest text-brand-300 hover:text-brand-200">
                    <span x-text="open ? 'Close' : 'Edit'">Edit</span>
                </button>
                <form method="POST" action="{{ route('brief-questions.destroy', $question) }}{{ $client ? '?client='.$client->id : '' }}"
                      onsubmit="return confirm('Archive this question? New briefs stop asking it. Answers already given are kept and stay visible on the client page.')">
                    @csrf @method('DELETE')
                    <button type="submit" class="text-xs font-semibold uppercase tracking-widest text-brand-100/50 hover:text-red-200">
                        Archive
                    </button>
                </form>
            @else
                <form method="POST" action="{{ route('brief-questions.restore', $question) }}{{ $client ? '?client='.$client->id : '' }}">
                    @csrf
                    <button type="submit" class="text-xs font-semibold uppercase tracking-widest text-brand-300 hover:text-brand-200">
                        Restore
                    </button>
                </form>
            @endif
        </div>
    </div>

    {{-- Inline, because a separate page for one label is a page nobody wants
         to walk to and back from. --}}
    <div x-show="open" x-cloak class="mt-3 pt-3 border-t border-white/10">
        <form method="POST" action="{{ route('brief-questions.update', $question) }}{{ $client ? '?client='.$client->id : '' }}">
            @csrf @method('PUT')

            {{-- Carried so the scope survives the save. Without these the
                 controller would read a private question as a shared one and
                 quietly move it onto everybody's brief. --}}
            @if ($question->client_id)
                <input type="hidden" name="client_id" value="{{ $question->client_id }}">
                <input type="hidden" name="group_label" value="{{ $question->group_label }}">
            @else
                <input type="hidden" name="step_id" value="{{ $question->step_id }}">
            @endif

            @include('brief-questions._fields', [
                'steps' => $steps,
                'types' => $types,
                'question' => $question,
                'stepId' => $question->step_id,
            ])

            <div class="flex items-center gap-3 mt-3">
                <x-primary-button>Save changes</x-primary-button>
                <button type="button" @click="open = false"
                        class="text-xs font-semibold uppercase tracking-widest text-brand-100/60 hover:text-white">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</li>
