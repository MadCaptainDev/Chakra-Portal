@props(['disabled' => false])

{{-- The <option> list itself is painted by the OS, not by these classes --
     `.theme-dark select option` in app.css handles that half. --}}
<select @disabled($disabled) {{ $attributes->merge(['class' => 'bg-white/5 border-white/15 text-white focus:bg-white/[0.07] focus:border-brand-400 focus:ring-brand-400 rounded-md min-h-[44px] w-full disabled:opacity-60']) }}>
    {{ $slot }}
</select>
