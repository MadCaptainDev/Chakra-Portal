@props([
    'name',
    'label',
    'type' => 'text',
    'value' => '',
    'placeholder' => null,
    'autocomplete' => null,
    'autofocus' => false,
    'required' => true,
])

{{--
    One labelled field on the dark sign-in screens.

    The auth pages sit on the brand-900 ground, where the app's default
    x-text-input (white box, grey label) is unreadable. This is the dark
    equivalent, in one place so the five screens cannot drift apart.
--}}
@php $hasError = $errors->has($name); @endphp

<div {{ $attributes->only('class') }}>
    <label for="{{ $name }}" class="block mb-2 text-xs font-semibold uppercase tracking-[0.08em] text-brand-100/70">
        {{ $label }}
    </label>

    <input id="{{ $name }}"
           name="{{ $name }}"
           type="{{ $type }}"
           value="{{ $value }}"
           @if ($placeholder) placeholder="{{ $placeholder }}" @endif
           @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
           @if ($autofocus) autofocus @endif
           @if ($required) required @endif
           @if ($hasError) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif
           @class([
               'w-full min-h-[46px] px-3.5 rounded-md bg-white/5 text-white placeholder:text-brand-100/35',
               'border transition-colors caret-brand-400',
               'focus:outline-none focus:ring-2 focus:ring-brand-400/60 focus:border-brand-400',
               'border-red-400/70' => $hasError,
               'border-white/15 hover:border-white/30' => ! $hasError,
           ])>

    @error($name)
        <p id="{{ $name }}-error" class="mt-2 text-sm text-red-300">{{ $message }}</p>
    @enderror
</div>
