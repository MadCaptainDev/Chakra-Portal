<x-app-layout title="Quick Replies">
    <x-slot name="header">
        <x-page-header title="Quick Replies" eyebrow="WhatsApp CRM"
                       subtitle="Saved replies the team can drop into a conversation without retyping them.">
            <x-slot name="actions">
                <x-btn :href="route('whatsapp-crm.quick-replies.create')" icon="plus">New quick reply</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-4">
        @if ($quickReplies->isEmpty())
            <x-empty-state message="No quick replies yet.">
                <x-btn :href="route('whatsapp-crm.quick-replies.create')" icon="plus" size="sm">Create your first quick reply</x-btn>
            </x-empty-state>
        @else
            {{-- Mobile: card list --}}
            <div class="md:hidden space-y-3">
                @foreach ($quickReplies as $quickReply)
                    <div class="bg-white/5 shadow-sm rounded-lg p-4">
                        <p class="font-semibold text-white truncate">{{ $quickReply->title }}</p>
                        <p class="text-sm text-brand-100/60 line-clamp-2 mt-1">{{ $quickReply->content }}</p>
                        <div class="mt-3 flex items-center gap-4">
                            <a href="{{ route('whatsapp-crm.quick-replies.edit', $quickReply) }}" class="text-brand-500 font-semibold text-sm min-h-[44px] flex items-center">Edit</a>
                            <form method="POST" action="{{ route('whatsapp-crm.quick-replies.destroy', $quickReply) }}" onsubmit="return confirm('Delete this quick reply?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-300 font-semibold text-sm min-h-[44px]">Delete</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Desktop: table --}}
            <x-card class="hidden md:block overflow-x-auto">
                <table class="min-w-full divide-y divide-white/10">
                    <thead class="bg-brand-900/40">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Title</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Message</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @foreach ($quickReplies as $quickReply)
                            <tr>
                                <td class="px-6 py-4 text-sm font-medium text-white">{{ $quickReply->title }}</td>
                                <td class="px-6 py-4 text-sm text-brand-100/60 max-w-md truncate">{{ $quickReply->content }}</td>
                                <td class="px-6 py-4 text-right text-sm space-x-3 whitespace-nowrap">
                                    <a href="{{ route('whatsapp-crm.quick-replies.edit', $quickReply) }}" class="text-brand-500 hover:text-brand-300 font-semibold">Edit</a>
                                    <form method="POST" action="{{ route('whatsapp-crm.quick-replies.destroy', $quickReply) }}" class="inline" onsubmit="return confirm('Delete this quick reply?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-300 hover:text-red-200 font-semibold">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
        @endif

        <div>
            {{ $quickReplies->links() }}
        </div>
    </div>
</x-app-layout>
