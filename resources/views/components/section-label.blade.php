@props([
    // Not itself a color name -- callers needing a status tint (green/red/
    // amber) still write their own class via $attributes, same as before.
    // This only standardizes the shape: size, weight, case, tracking.
    'dark' => false,
])

{{--
    The uppercase-tracked micro-label idiom -- a section eyebrow, a small
    heading above a group of tiles, a "Studio"/"Team"/"Money" divider. It
    recurred across the app in five near-identical hand-typed forms (10px vs
    11px, tracking-wide vs tracking-wider vs tracking-widest vs an arbitrary
    tracking-[0.12em]/[0.16em]) for what always reads as the same element.
    One size going forward: 11px, semibold, tracking-wider.

    NOT for table column headers (<th>) -- those are a different role with
    their own established convention (text-xs text-gray-500 tracking-wide)
    and stay as they are.
--}}
<p {{ $attributes->merge(['class' => 'text-[11px] font-semibold uppercase tracking-wider '
    .($dark ? 'text-brand-300' : 'text-brand-600')]) }}>
    {{ $slot }}
</p>
