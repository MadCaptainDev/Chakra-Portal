{{-- The primary action on the dark sign-in screens. Dark type on the accent,
     which is the combination that actually passes contrast -- white on
     brand-400 is 2.16:1. --}}
<button {{ $attributes->merge([
    'type' => 'submit',
    'class' => 'inline-flex items-center justify-center min-h-[48px] px-6 rounded-md
                bg-brand-400 text-brand-900 text-sm font-semibold uppercase tracking-widest
                hover:bg-brand-500 active:bg-brand-500 transition-colors
                focus:outline-none focus:ring-2 focus:ring-brand-400/60 focus:ring-offset-2 focus:ring-offset-brand-900',
]) }}>
    {{ $slot }}
</button>
