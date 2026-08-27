@php
    use App\Models\RoutineField;

    /**
     * The capture fields under one ticked duty -- pulled out of
     * _routine-duty so a checklist subtask can show the same inputs without
     * repeating the field-type switch.
     */
@endphp

<div x-show="ticked.includes('{{ $key }}')" x-cloak class="mt-2 pl-8 space-y-2">
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

    @if ($outstanding > 1)
        <p class="text-xs text-brand-100/50">
            Saving closes all {{ $outstanding }} outstanding days with these values.
        </p>
    @endif
</div>
