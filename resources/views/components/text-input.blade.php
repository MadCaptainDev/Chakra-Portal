@props(['disabled' => false])

{{-- One field treatment for the dark plane: a glass well on the brand-900
     ground rather than a white box, which is the single change that stops a
     form reading as a light-mode leftover. --}}
<input @disabled($disabled) {{ $attributes->merge(['class' => 'bg-white/5 border-white/15 text-white placeholder-brand-100/40 focus:bg-white/[0.07] focus:border-brand-400 focus:ring-brand-400 rounded-md min-h-[44px] w-full disabled:opacity-60']) }}>
