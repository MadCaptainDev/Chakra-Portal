{{-- WhatsApp, one tap away on the marketing pages: ready messages (one about
     the reel the visitor was looking at) or their own words. See site.js waChat. --}}
<div x-data="waChat({ number: @js(config('studio.whatsapp')) })"
     x-init="const m = document.querySelector('meta[name=wa-context]'); if (m) context = { title: m.content, client: m.dataset.client || null }"
     @keydown.escape.window="open = false" class="fixed bottom-4 right-4 z-40 sm:bottom-6 sm:right-6">
    <div x-show="open" x-cloak x-transition.origin.bottom.right
         class="absolute bottom-[4.5rem] right-0 w-[min(92vw,360px)] overflow-hidden rounded-2xl bg-white text-brand-900 shadow-2xl ring-1 ring-black/10">
        <div class="flex items-center gap-3 bg-[#075E54] px-4 py-3 text-white">
            <span class="flex h-10 w-10 items-center justify-center rounded-full bg-white/15">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm5.3 14.2c-.2.6-1.3 1.2-1.8 1.2-.5.1-1 .2-3.3-.7-2.8-1.1-4.5-3.9-4.7-4.1-.1-.2-1.1-1.5-1.1-2.9s.7-2 1-2.3c.2-.3.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.8 2c.1.2.1.4 0 .5l-.4.6-.4.4c-.1.2-.3.3-.1.6.2.3.8 1.3 1.7 2.1 1.2 1 2.1 1.3 2.4 1.5.3.1.5.1.6-.1l.9-1c.2-.3.4-.2.6-.1l1.9.9c.3.1.5.2.5.3.1.2.1.7-.2 1.4z"/></svg>
            </span>
            <div class="min-w-0 flex-1">
                <p class="font-semibold leading-tight">Chakra Productions</p>
                <p class="text-xs text-white/80">Usually replies within an hour</p>
            </div>
            <button type="button" @click="open = false" class="rounded-full p-1.5 text-white/80 hover:bg-white/15" aria-label="Close">✕</button>
        </div>
        <div class="space-y-2 bg-[#ECE5DD] p-3">
            <p class="text-xs font-medium text-brand-900/60">Tap a message to send it on WhatsApp:</p>
            <template x-for="o in options" :key="o.key">
                <button type="button" @click="go(o)"
                        class="block w-full rounded-xl rounded-tl-sm bg-white px-3 py-2.5 text-left text-sm leading-snug shadow-sm transition hover:bg-emerald-50 active:scale-[0.99]"
                        x-text="o.text"></button>
            </template>
            <form @submit.prevent="sendOwn()" class="flex items-end gap-2 pt-1">
                <textarea x-model="own" rows="1" maxlength="500" placeholder="Or type your own message…"
                          class="min-h-[44px] flex-1 resize-none rounded-full border-0 bg-white px-4 py-2.5 text-[15px] shadow-sm focus:ring-2 focus:ring-emerald-500"></textarea>
                <button type="submit" :disabled="!own.trim()" aria-label="Send on WhatsApp"
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#25D366] text-white shadow disabled:opacity-40">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor"><path d="M3.4 20.4 21 12 3.4 3.6 3.4 10l12.6 2-12.6 2z"/></svg>
                </button>
            </form>
        </div>
    </div>

    <button type="button" @click="open = ! open; if (open) window.siteTrack?.('whatsapp', 'opened', 'Opened the chat box')"
            class="flex items-center gap-2 rounded-full bg-[#25D366] py-3 pl-3 pr-3 text-white shadow-[0_10px_30px_-8px_rgba(37,211,102,.7)] transition hover:scale-105 sm:pr-5"
            :aria-expanded="open ? 'true' : 'false'" aria-label="Chat with us on WhatsApp">
        <svg class="h-7 w-7" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm5.3 14.2c-.2.6-1.3 1.2-1.8 1.2-.5.1-1 .2-3.3-.7-2.8-1.1-4.5-3.9-4.7-4.1-.1-.2-1.1-1.5-1.1-2.9s.7-2 1-2.3c.2-.3.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.8 2c.1.2.1.4 0 .5l-.4.6-.4.4c-.1.2-.3.3-.1.6.2.3.8 1.3 1.7 2.1 1.2 1 2.1 1.3 2.4 1.5.3.1.5.1.6-.1l.9-1c.2-.3.4-.2.6-.1l1.9.9c.3.1.5.2.5.3.1.2.1.7-.2 1.4z"/></svg>
        <span class="hidden text-sm font-bold sm:inline">Chat with us</span>
    </button>
</div>
