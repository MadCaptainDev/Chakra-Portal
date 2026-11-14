{{-- The mic, where Send sits while there is nothing typed. $target as in _voice-recorder. --}}
<button type="button" x-show="{{ $when }} && rec.state === 'idle'" @click="startVoice({{ $target }})" aria-label="Record a voice note" title="Record a voice note"
        class="{{ $btn }} {{ $size ?? 'h-12 w-12' }} shrink-0 rounded-full bg-amber-400 text-brand-900 shadow-lg hover:bg-amber-300">
    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor"><path d="M12 15a3 3 0 0 0 3-3V6a3 3 0 1 0-6 0v6a3 3 0 0 0 3 3zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 6 6.92V21h2v-2.08A7 7 0 0 0 19 12z"/></svg>
</button>
