@props([
    // Kept so the ~40 call sites that name the dark prop -- written while the
    // Dashboard was the only dark screen still parse. Both branches now
    // resolve to the same colour: everything signed-in is on the dark plane.
    'dark' => true,
])

{{--
    The uppercase-tracked micro-label idiom -- a section eyebrow, a small
    heading above a group of tiles, a "Studio"/"Team"/"Money" divider. It
    recurred across the app in five near-identical hand-typed forms (10px vs
    11px, tracking-wide vs tracking-wider vs tracking-widest vs an arbitrary
    tracking-[0.12em]/[0.16em]) for what always reads as the same element.
    One size going forward: 11px, semibold, tracking-wider.

    Callers needing a status tint (green/red/amber) still write their own
    class via $attributes, same as before.
--}}
<p {{ $attributes->merge(['class' => 'text-[11px] font-semibold uppercase tracking-wider text-brand-300']) }}>
    {{ $slot }}
</p>
