{{-- Recording a voice note, in place of the text box. $target: the JS expression
     naming where it goes -- "'main'" (a new comment) or "c.id" (a reply). --}}
<div x-show="recording({{ $target }})" x-cloak class="flex w-full items-center gap-2">
    <button type="button" @click="cancelVoice()" aria-label="Delete the recording"
            class="{{ $btn }} h-11 w-11 shrink-0 rounded-full text-red-300 hover:bg-red-400/10">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/></svg>
    </button>

    <div class="flex h-12 min-w-0 flex-1 items-center gap-2.5 rounded-2xl bg-[#0b1d29] px-3">
        <template x-if="rec.state === 'recording'">
            <div class="flex min-w-0 flex-1 items-center gap-2.5">
                <span class="h-2.5 w-2.5 shrink-0 animate-pulse rounded-full bg-red-500"></span>
                <span class="w-10 shrink-0 font-mono text-sm font-bold text-red-200" x-text="clock(rec.secs)"></span>
                <div class="flex h-8 min-w-0 flex-1 items-center justify-end gap-[3px] overflow-hidden">
                    <template x-for="(b, i) in rec.live" :key="i">
                        <span class="w-[3px] shrink-0 rounded-full bg-red-300/80 transition-all" :style="`height:${Math.max(10, b)}%`"></span>
                    </template>
                </div>
            </div>
        </template>
        <template x-if="rec.state !== 'recording'">
            <div class="flex min-w-0 flex-1 items-center gap-2.5">
                <button type="button" @click="playVoice(null)" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white/10 text-white" aria-label="Listen before sending">
                    <svg x-show="!(vp.id === 'draft' && vp.playing)" class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                    <svg x-show="vp.id === 'draft' && vp.playing" class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M7 5h4v14H7zm6 0h4v14h-4z"/></svg>
                </button>
                <div class="flex h-7 min-w-0 flex-1 items-center gap-[2px] overflow-hidden">
                    <template x-for="(b, i) in voiceBars(rec.peaks)" :key="i">
                        <span class="w-[3px] shrink-0 rounded-full" :class="i / 32 < voiceProgress('draft', rec.secs) ? 'bg-amber-300' : 'bg-white/30'" :style="`height:${Math.max(12, b)}%`"></span>
                    </template>
                </div>
                <span class="shrink-0 font-mono text-xs text-brand-100/60" x-text="clock(vp.id === 'draft' ? vp.t : rec.secs)"></span>
            </div>
        </template>
    </div>

    <button type="button" x-show="rec.state === 'recording'" @click="stopVoice()" aria-label="Stop recording"
            class="{{ $btn }} h-12 w-12 shrink-0 rounded-full bg-red-500 text-white shadow-lg">
        <span class="h-4 w-4 rounded-[3px] bg-white"></span>
    </button>
    <button type="button" x-show="rec.state !== 'recording'" @click="sendVoice()" :disabled="rec.state === 'sending'" aria-label="Send voice note"
            class="{{ $btn }} h-12 w-12 shrink-0 rounded-full bg-amber-400 text-brand-900 shadow-lg hover:bg-amber-300">
        <span x-show="rec.state === 'sending'" class="h-4 w-4 animate-spin rounded-full border-2 border-brand-900/30 border-t-brand-900"></span>
        <svg x-show="rec.state !== 'sending'" class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor"><path d="M3.4 20.4 21 12 3.4 3.6 3.4 10l12.6 2-12.6 2z"/></svg>
    </button>
</div>
