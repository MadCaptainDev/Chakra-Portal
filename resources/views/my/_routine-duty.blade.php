@php
    use App\Models\RoutineField;

    /**
     * One duty, however many days it is behind. The backlog is a line of text,
     * not four identical cards.
     */
    $occurrence = $duty['oldest'];
    $fields = $occurrence->applicableFields();
    $key = $duty['key'];
    $inputId = 'duty-'.md5($key);
@endphp

<x-card padding="sm" class="{{ $duty['is_overdue'] ? 'ring-1 ring-amber-400/30' : '' }}">
    <label for="{{ $inputId }}" class="flex items-start gap-3 cursor-pointer">
        <input type="checkbox" id="{{ $inputId }}" name="duties[]" value="{{ $key }}"
               x-model="ticked"
               class="mt-0.5 h-5 w-5 rounded bg-white/10 border-white/25 text-brand-400 focus:ring-brand-400 shrink-0">

        <span class="min-w-0 flex-1">
            <span class="block font-semibold text-white">{{ $duty['routine']?->title }}</span>

            <span class="block text-xs text-brand-100/60 mt-0.5">
                @if ($duty['is_overdue'])
                    <span class="font-semibold text-amber-200">
                        {{ $duty['days_late'] }} {{ Str::plural('day', $duty['days_late']) }} late
                    </span>
                    &middot; since {{ $occurrence->due_on->format('D, j M') }}
                @else
                    Due today
                @endif

                @if ($duty['outstanding'] > 1)
                    &middot; {{ $duty['outstanding'] }} outstanding
                @endif

                @if ($duty['subject_label'])
                    &middot; {{ $duty['subject_label'] }}
                @endif

                @if ($duty['checkpoint'])
                    &middot; {{ $duty['checkpoint']->name }}
                @endif

                &middot; {{ $duty['routine']?->isShared() ? 'shared' : 'yours' }}
            </span>
        </span>
    </label>

    @if ($fields->isNotEmpty())
        <div x-show="ticked.includes('{{ $key }}')" x-cloak class="mt-3 pl-8 space-y-2">
            @foreach ($fields as $field)
                <div>
                    <label class="block text-xs font-medium text-brand-100/70 mb-0.5">{{ $field->label }}</label>
                    @if ($field->type === RoutineField::TYPE_BOOLEAN)
                        <select name="values[{{ $key }}][{{ $field->key }}]"
                                class="block w-full rounded-md border-white/15 text-sm">
                            <option value="0">No</option>
                            <option value="1" @selected($field->resolvedDefault())>Yes</option>
                        </select>
                    @elseif ($field->type === RoutineField::TYPE_NUMBER)
                        <input type="number" step="any" name="values[{{ $key }}][{{ $field->key }}]"
                               value="{{ $field->resolvedDefault() }}"
                               class="block w-full rounded-md border-white/15 text-sm">
                    @else
                        <input type="text" name="values[{{ $key }}][{{ $field->key }}]"
                               value="{{ $field->resolvedDefault() }}"
                               class="block w-full rounded-md border-white/15 text-sm">
                    @endif
                </div>
            @endforeach

            @if ($duty['outstanding'] > 1)
                <p class="text-xs text-brand-100/50">
                    Saving closes all {{ $duty['outstanding'] }} outstanding days with these values.
                </p>
            @endif
        </div>
    @endif
</x-card>
