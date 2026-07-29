@props(['disabled' => false])

<textarea @disabled($disabled) {{ $attributes->merge(['class' => 'border-gray-300 focus:border-brand-400 focus:ring-brand-400 rounded-md shadow-sm w-full']) }}>{{ $slot }}</textarea>
