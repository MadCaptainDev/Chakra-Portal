@csrf

<div class="mb-6">
    <x-input-label for="title" value="Title" />
    <x-text-input id="title" name="title" type="text" class="mt-1" required autofocus
        value="{{ old('title', $quickReply->title ?? '') }}" placeholder="e.g. Booking confirmed" />
    <x-input-error :messages="$errors->get('title')" class="mt-2" />
</div>

<div class="mb-6">
    <x-input-label for="content" value="Message" />
    <x-textarea id="content" name="content" rows="5" class="mt-1" required>{{ old('content', $quickReply->content ?? '') }}</x-textarea>
    <x-input-error :messages="$errors->get('content')" class="mt-2" />
</div>

<div class="flex flex-wrap items-center gap-4">
    <x-primary-button>Save Quick Reply</x-primary-button>
    <a href="{{ route('whatsapp-crm.quick-replies.index') }}" class="text-sm text-brand-100/70 hover:text-white">Cancel</a>
</div>
