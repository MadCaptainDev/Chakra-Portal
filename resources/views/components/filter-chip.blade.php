@props(['active' => false, 'count' => null])

<button {{ $attributes->merge([
    'type' => 'button',
    'class' => 'inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-full transition ' .
        ($active
            ? 'bg-brand-500 text-white shadow-sm'
            : 'bg-gray-100 text-gray-600 hover:bg-gray-200')
]) }}>
    {{ $slot }}
    @if ($count !== null)
        <span @class([
            'tabular-nums',
            'text-white/80' => $active,
            'text-gray-400' => ! $active,
        ])>{{ $count }}</span>
    @endif
</button>
