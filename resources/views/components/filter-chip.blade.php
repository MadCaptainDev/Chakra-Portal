@props(['active' => false, 'count' => null])

<button {{ $attributes->merge([
    'type' => 'button',
    'class' => 'inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-full transition ' .
        ($active
            ? 'bg-brand-400 text-brand-900'
            : 'bg-white/10 text-brand-100/80 hover:bg-white/[0.16] hover:text-white')
]) }}>
    {{ $slot }}
    @if ($count !== null)
        <span @class([
            'tabular-nums',
            'text-brand-100/70' => $active,
            'text-brand-100/50' => ! $active,
        ])>{{ $count }}</span>
    @endif
</button>
