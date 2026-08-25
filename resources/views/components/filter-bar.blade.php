@props([])

{{--
    Shared toolbar strip for list filters (search, chips, selects). Keeps
    invoice/shoot-style filter stacks visually consistent without each page
    inventing its own card padding.
--}}
<x-card padding="sm" {{ $attributes->merge(['class' => 'space-y-3']) }}>
    {{ $slot }}
</x-card>
