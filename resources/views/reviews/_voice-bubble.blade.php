{{-- A voice note in the thread, WhatsApp style, with what was said under it. $v: "c" or "r". --}}
<template x-if="{{ $v }}.voice">
    <div class="mt-1.5">
        <div class="flex w-full max-w-sm items-center gap-2.5 rounded-2xl bg-emerald-500/10 px-2.5 py-2 ring-1 ring-emerald-400/20">
            <button type="button" @click="playVoice({{ $v }})" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-400 text-brand-900 transition active:scale-95"
                    :aria-label="vp.id === {{ $v }}.id && vp.playing ? 'Pause' : 'Play voice note'">
                <svg x-show="!(vp.id === {{ $v }}.id && vp.playing)" class="ml-0.5 h-5 w-5" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                <svg x-show="vp.id === {{ $v }}.id && vp.playing" class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor"><path d="M7 5h4v14H7zm6 0h4v14h-4z"/></svg>
            </button>
            <div class="flex h-8 min-w-0 flex-1 items-center gap-[2px] overflow-hidden">
                <template x-for="(b, i) in voiceBars({{ $v }}.voice_peaks)" :key="i">
                    <span class="w-[3px] shrink-0 rounded-full transition-colors" :class="i / 32 < voiceProgress({{ $v }}.id, {{ $v }}.voice_seconds) ? 'bg-emerald-300' : 'bg-white/30'" :style="`height:${Math.max(12, b)}%`"></span>
                </template>
            </div>
            <span class="shrink-0 font-mono text-[11px] text-emerald-100/70" x-text="clock(vp.id === {{ $v }}.id ? vp.t : {{ $v }}.voice_seconds)"></span>
        </div>
        <p x-show="{{ $v }}.voice_state === 'pending'" class="mt-1 flex items-center gap-1.5 text-[11px] text-brand-100/45">
            <span class="h-3 w-3 animate-spin rounded-full border border-white/20 border-t-white/70"></span> Writing down what was said… (Gemini is busy at times; it keeps trying)
        </p>
        <p x-show="{{ $v }}.voice_state === 'failed'" class="mt-1 flex items-center gap-2 text-[11px] text-brand-100/45">
            Could not write this one down.
            <button type="button" @click="retranscribe({{ $v }})" class="font-semibold text-amber-200 underline underline-offset-2 hover:text-amber-100">Try again</button>
        </p>
        <p x-show="{{ $v }}.voice_english" class="mt-1 text-[11px] italic text-brand-100/50" x-text="'In English: ' + {{ $v }}.voice_english"></p>
    </div>
</template>
