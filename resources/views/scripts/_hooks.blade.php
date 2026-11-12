{{--
    Hook ideas: five stronger opening lines for this script, from Gemini.
    $writeUrl / $useUrl may hold __ID__; $dark for the black on-set reader.
--}}
@php
    $dark = $dark ?? false;
    $panel = $dark ? 'bg-white/[0.06]' : 'bg-[#0f2230]';
@endphp
<div class="space-y-3">
    <div class="space-y-2 rounded-xl {{ $panel }} p-3">
        <textarea x-model="note" rows="2" maxlength="600"
                  placeholder="Note (optional): the angle you want, who it is for, words to avoid…"
                  class="w-full rounded-lg border-0 bg-white/[0.05] p-2.5 text-[14px] text-white placeholder:text-white/35 focus:ring-1 focus:ring-amber-400"></textarea>
        <div class="flex flex-wrap items-center gap-2">
            <select x-model="language" class="h-10 rounded-lg border-0 bg-white/[0.08] py-0 pl-2.5 pr-8 text-[13px] text-white focus:ring-1 focus:ring-amber-400" aria-label="Language">
                <option value="script" class="text-black">Same as the script</option>
                <option value="english" class="text-black">English</option>
                <option value="tanglish" class="text-black">Tanglish</option>
                <option value="tamil" class="text-black">Tamil + English</option>
            </select>
            <button type="button" @click="generate()" :disabled="busy"
                    class="inline-flex h-10 items-center gap-2 rounded-lg bg-amber-400 px-4 text-sm font-bold text-black disabled:opacity-50">
                <span x-show="busy" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-black/30 border-t-black"></span>
                <span x-text="busy ? 'Writing hooks…' : (hooks.length ? 'New hooks' : 'Give me 5 hooks')"></span>
            </button>
        </div>
        <p x-show="error" x-cloak class="text-xs text-red-300" x-text="error"></p>
        <p x-show="info" x-cloak class="text-[11px] text-white/45" x-text="info"></p>
    </div>

    <template x-for="(h, n) in hooks" :key="n">
        <div class="rounded-xl {{ $panel }} p-3.5 ring-1 ring-white/10">
            <p class="mb-1 text-[10px] font-bold uppercase tracking-[0.14em] text-amber-300" x-text="(n + 1) + (h.angle ? ' · ' + h.angle : '')"></p>
            <p class="{{ $dark ? 'text-xl' : 'text-[16px]' }} font-semibold leading-snug text-white" x-text="h.spoken"></p>
            <p x-show="h.on_screen" class="mt-1.5 text-[13px] text-white/75"><span class="text-white/45">On screen:</span> <span class="font-semibold" x-text="h.on_screen"></span></p>
            <p x-show="h.first_shot" class="mt-0.5 text-[13px] text-white/60"><span class="text-white/40">Shot:</span> <span x-text="h.first_shot"></span></p>
            <div class="mt-2.5 flex flex-wrap gap-2">
                <button type="button" @click="copy(h, n)" class="h-9 rounded-lg bg-white/10 px-3 text-xs font-semibold text-white" x-text="copied === n ? 'Copied' : 'Copy'"></button>
                <button type="button" x-show="canUse" @click="use(h, n)" :disabled="!!added[n]"
                        class="h-9 rounded-lg bg-white px-3 text-xs font-bold text-black disabled:opacity-50" x-text="added[n] ? 'Added to the script ✓' : 'Add to the top of the script'"></button>
            </div>
        </div>
    </template>
</div>
