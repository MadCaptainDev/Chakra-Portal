@props([
    // Sits under the rec dot. Keep it short -- this renders at 10px.
    'label' => 'Loading',
    // true: a fixed, full-viewport overlay for a genuine page-level wait
    // (first load, a full-page async action). false: sits inline wherever
    // it is placed -- inside a form, a card, a modal.
    'overlay' => false,
])

{{--
    The "film strip" loader (design/Loading animation options, variant 1B).
    Frames slide left inside a dark reel, a bar climbs underneath, a rec dot
    blinks by the label -- reads as a render progressing, which is what most
    genuine waits in this app actually are (an upload, a sync, a generated
    file). Keyframes/utility classes live in resources/css/app.css, guarded
    under prefers-reduced-motion the same way every other loop in the app is.
--}}
<div {{ $attributes->merge(['class' => $overlay
    ? 'fixed inset-0 z-50 flex items-center justify-center bg-white/90 backdrop-blur-sm'
    : 'inline-flex']) }}
    role="status" aria-live="polite"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 scale-95"
    x-transition:enter-end="opacity-100 scale-100"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100 scale-100"
    x-transition:leave-end="opacity-0 scale-95">

    <x-card padding="md" class="flex flex-col items-center gap-5">
        <div class="w-[200px] overflow-hidden rounded-md bg-brand-900 py-[7px]">
            <div class="flex w-[200%] gap-2 animate-film-slide">
                {{--
                    Literal classes, not an interpolated bg-brand-{{ $shade }}
                    string -- Tailwind's JIT scanner only picks up class
                    names it can see whole in the source, so a built class
                    name here would silently never make it into the
                    compiled CSS.
                --}}
                @foreach ([
                    'bg-brand-500', 'bg-brand-300', 'bg-brand-700', 'bg-brand-400',
                    'bg-brand-500', 'bg-brand-300', 'bg-brand-700', 'bg-brand-400',
                ] as $shade)
                    <div class="h-5 w-[34px] shrink-0 rounded-[3px] {{ $shade }}"></div>
                @endforeach
            </div>
        </div>

        <div class="flex w-[200px] flex-col gap-2">
            <div class="h-1 overflow-hidden rounded-full bg-gray-200">
                <div class="h-full rounded-full bg-brand-500 animate-bar-grow"></div>
            </div>

            <div class="flex items-center gap-1.5">
                <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-red-500 animate-rec-blink" aria-hidden="true"></span>
                <span class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">
                    {{ $label }}<span class="sr-only">, please wait</span>
                </span>
            </div>
        </div>
    </x-card>
</div>
