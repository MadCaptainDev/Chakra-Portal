@props(['disabled' => false])

<textarea @disabled($disabled) {{ $attributes->merge(['class' => 'bg-white/5 border-white/15 text-white placeholder-brand-100/40 focus:bg-white/[0.07] focus:border-brand-400 focus:ring-brand-400 rounded-md w-full disabled:opacity-60']) }}>{{ $slot }}</textarea>
