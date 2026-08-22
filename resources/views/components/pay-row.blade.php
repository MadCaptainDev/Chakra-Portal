@props([
    'action',
    // Already formatted by the caller (Y-m vs Y-m-d varies by screen, so this
    // stays their decision, not something this component guesses at).
    'month',
    'due',
    // null (the default) means "always offer to pay the full due amount" --
    // the expenses "Needs attention" board's shape, where a row is always a
    // shortfall to clear, never an existing payment to revise. Pass the
    // actual paid amount to get the salaries/bills/EMI shape instead: once
    // something is paid, the button becomes an edit ("Update"/"Save") that
    // pre-fills with what was actually paid, not the original due amount.
    'paid' => null,
    // EMI's own shape: a labelled field stacked above a full-width button,
    // instead of the inline number-input-plus-button every other screen
    // uses. Same form, same inputs -- only the layout differs.
    'stacked' => false,
    'label' => null,
    'hint' => null,
    'payLabel' => 'Pay',
    'updateLabel' => 'Update',
])

@php
    $isPaid = $paid !== null && $paid > 0;
    $value = $isPaid ? number_format($paid, 2, '.', '') : number_format($due, 2, '.', '');
    $placeholder = number_format($due, 2, '.', '');

    $buttonClass = 'min-h-[44px] px-3 rounded-md text-xs font-semibold uppercase tracking-wider '
        .($isPaid ? 'bg-green-100 text-green-800 hover:bg-green-200' : 'bg-brand-400 text-brand-900 hover:bg-brand-500');
    $buttonLabel = $isPaid ? $updateLabel : $payLabel;
@endphp

@if ($stacked)
    <form method="POST" action="{{ $action }}" {{ $attributes->merge(['class' => 'space-y-2']) }}>
        @csrf
        <input type="hidden" name="month" value="{{ $month }}">
        <div class="flex items-end gap-2">
            <div class="min-w-0 flex-1">
                @if ($label)
                    <label class="block text-xs font-semibold text-gray-700 mb-1">
                        {{ $label }}
                        @if ($hint)<span class="font-normal text-gray-500">({{ $hint }})</span>@endif
                    </label>
                @endif
                <input type="number" step="0.01" min="0" name="amount_paid"
                       value="{{ $value }}" placeholder="{{ $placeholder }}"
                       class="w-full rounded-md border-gray-300 shadow-sm text-sm text-right focus:border-brand-400 focus:ring-brand-400 min-h-[44px]">
            </div>
            <button type="submit" class="{{ $buttonClass }} shrink-0">{{ $buttonLabel }}</button>
        </div>
        {{-- EMI is the one caller using stacked, and the only one that needs
             a Cancel button / helper line under the input row (its own
             already-paid "Adjust" state is bespoke, outside this
             component's scope -- only the input+button row itself is
             shared). --}}
        {{ $slot }}
    </form>
@else
    <form method="POST" action="{{ $action }}" {{ $attributes->merge(['class' => 'flex items-center gap-2 shrink-0']) }}>
        @csrf
        <input type="hidden" name="month" value="{{ $month }}">
        <input type="number" step="0.01" min="0" name="amount_paid"
               value="{{ $value }}" placeholder="{{ $placeholder }}"
               class="w-24 sm:w-28 rounded-md border-gray-300 shadow-sm text-sm text-right focus:border-brand-400 focus:ring-brand-400 min-h-[44px]">
        <button type="submit" class="{{ $buttonClass }}">{{ $buttonLabel }}</button>
    </form>
@endif
