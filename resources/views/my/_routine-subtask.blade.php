@php
    /**
     * One line inside a checklist task -- the account, not the routine (the
     * routine title is already the checklist's own header).
     */
    $occurrence = $duty['oldest'];
    $fields = $occurrence->applicableFields();
    $key = $duty['key'];
    $inputId = 'duty-'.md5($key);
@endphp

<label for="{{ $inputId }}" class="flex items-start gap-3 cursor-pointer">
    <input type="checkbox" id="{{ $inputId }}" name="duties[]" value="{{ $key }}"
           x-model="ticked"
           class="mt-0.5 h-4 w-4 rounded bg-white/10 border-white/25 text-brand-400 focus:ring-brand-400 shrink-0">

    <span class="min-w-0 flex-1">
        <span class="block text-sm text-white">{{ $duty['subject_label'] ?? $duty['routine']?->title }}</span>

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
        </span>
    </span>
</label>

@if ($fields->isNotEmpty())
    @include('my._routine-fields', ['fields' => $fields, 'key' => $key, 'outstanding' => $duty['outstanding']])
@endif
