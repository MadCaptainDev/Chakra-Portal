{{-- A copyable code block. $code is shown verbatim; $label is optional. --}}
<div x-data="{ copied: false }" class="mt-2">
    @isset($label)
        <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-200 mb-1.5">{{ $label }}</p>
    @endisset
    <div class="relative">
        <pre class="w-full overflow-x-auto rounded-lg bg-black/30 ring-1 ring-white/10 p-3 pr-20 text-xs leading-relaxed text-brand-50 whitespace-pre"><code x-ref="code">{{ $code }}</code></pre>
        <button type="button"
                @click="navigator.clipboard.writeText($refs.code.textContent); copied = true; setTimeout(() => copied = false, 2000)"
                class="absolute top-2 right-2 inline-flex items-center min-h-[30px] px-2.5 rounded-md bg-white/10 text-[11px] font-semibold uppercase tracking-wider text-brand-100 hover:bg-white/20 transition-colors">
            <span x-show="! copied">Copy</span>
            <span x-show="copied" x-cloak>Copied</span>
        </button>
    </div>
</div>
