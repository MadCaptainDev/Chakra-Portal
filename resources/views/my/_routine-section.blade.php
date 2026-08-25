@php
    use App\Models\RoutineField;
@endphp

<section>
    <h2 class="text-sm font-semibold uppercase tracking-wider text-gray-500 mb-2">{{ $title }}</h2>

    @forelse ($items as $occurrence)
        @php
            $subjectLabel = $occurrence->subjectLabel();
            $fields = $occurrence->applicableFields();
        @endphp
        <x-card padding="sm" class="mb-2">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-semibold text-gray-900">{{ $occurrence->routine?->title }}</p>
                    <p class="text-xs text-gray-500 mt-0.5">
                        Due {{ $occurrence->due_on->format('D, j M') }}
                        @if ($subjectLabel) · {{ $subjectLabel }} @endif
                        @if ($occurrence->checkpoint) · {{ $occurrence->checkpoint->name }} @endif
                        @if ($occurrence->routine?->isShared())
                            · shared
                        @else
                            · yours
                        @endif
                    </p>
                </div>
            </div>

            <form method="POST" action="{{ route('my.routines.complete', $occurrence) }}" class="mt-3 space-y-2">
                @csrf
                @foreach ($fields as $field)
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-0.5">{{ $field->label }}</label>
                        @if ($field->type === RoutineField::TYPE_BOOLEAN)
                            <select name="values[{{ $field->key }}]" class="block w-full rounded-md border-gray-300 text-sm">
                                <option value="0">No</option>
                                <option value="1" @selected($field->resolvedDefault())>Yes</option>
                            </select>
                        @elseif ($field->type === RoutineField::TYPE_NUMBER)
                            <input type="number" step="any" name="values[{{ $field->key }}]"
                                   value="{{ $field->resolvedDefault() }}"
                                   class="block w-full rounded-md border-gray-300 text-sm">
                        @else
                            <input type="text" name="values[{{ $field->key }}]"
                                   value="{{ $field->resolvedDefault() }}"
                                   class="block w-full rounded-md border-gray-300 text-sm">
                        @endif
                    </div>
                @endforeach
                <div class="flex justify-end">
                    <x-btn type="submit" size="sm">Mark done</x-btn>
                </div>
            </form>
        </x-card>
    @empty
        <p class="text-sm text-gray-500 mb-4">Nothing here.</p>
    @endforelse
</section>
