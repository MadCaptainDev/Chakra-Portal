<x-app-layout title="Inbox">
    {{-- A chat app's two panes: conversations on the left, the open thread
         on the right (show.blade.php). With nothing open yet the right pane
         says so; on a phone the list is the whole screen. --}}
    <div class="-mx-4 sm:mx-0 -my-6 sm:my-0 h-[calc(100dvh-4rem)] sm:h-[calc(100dvh-7rem)] lg:h-[calc(100dvh-6rem)] flex sm:rounded-2xl overflow-hidden sm:ring-1 sm:ring-white/10 bg-white/[0.03]">
        <aside class="w-full lg:w-[22rem] xl:w-96 lg:border-r lg:border-white/10 min-h-0">
            @include('whatsapp-crm.inbox._sidebar')
        </aside>

        <section class="hidden lg:flex flex-1 items-center justify-center p-10 text-center">
            <div class="max-w-sm">
                <div class="mx-auto w-16 h-16 rounded-2xl bg-brand-400/15 flex items-center justify-center">
                    <x-icon name="chat" class="w-8 h-8 text-brand-300" />
                </div>
                <p class="mt-4 text-base font-semibold text-white">Pick a conversation</p>
                <p class="mt-1 text-sm text-brand-100/60">
                    Replies reach a contact as free text for 24 hours after their last message.
                    After that, WhatsApp only delivers an approved template &mdash; the reply box will offer one.
                </p>
            </div>
        </section>
    </div>
</x-app-layout>
