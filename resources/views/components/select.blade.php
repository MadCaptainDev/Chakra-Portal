@props(['disabled' => false])

<select @disabled($disabled) {{ $attributes->merge(['class' => 'border-gray-300 focus:border-brand-400 focus:ring-brand-400 rounded-md shadow-sm min-h-[44px] w-full']) }}>
    {{ $slot }}
</select>
