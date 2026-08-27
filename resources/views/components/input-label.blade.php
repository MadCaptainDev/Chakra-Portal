@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-medium text-sm text-brand-100/80']) }}>
    {{ $value ?? $slot }}
</label>
