@php
    // Renders bare -- the caller supplies the card chrome.
    $announcement = $announcement ?? null;
    $uid = $announcement?->id ?? 'new';
@endphp

<form method="POST" action="{{ $announcement ? route('announcements.update', $announcement) : route('announcements.store') }}">
    @csrf
    @if ($announcement)
        @method('PUT')
    @endif

    <div class="mb-4">
        <x-input-label :for="'ann_title_'.$uid" value="Title" />
        <x-text-input :id="'ann_title_'.$uid" name="title" type="text" class="mt-1 block w-full"
                      value="{{ old('title', $announcement->title ?? '') }}" required />
        <x-input-error :messages="$errors->get('title')" class="mt-2" />
    </div>

    <div class="mb-4">
        <x-input-label :for="'ann_body_'.$uid" value="Message" />
        <x-textarea :id="'ann_body_'.$uid" name="body" rows="4" class="mt-1" required>{{ old('body', $announcement->body ?? '') }}</x-textarea>
        <x-input-error :messages="$errors->get('body')" class="mt-2" />
    </div>

    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex flex-wrap items-center gap-4">
            <label class="inline-flex items-center min-h-[44px] gap-2">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1"
                       @checked(old('is_active', $announcement->is_active ?? true))
                       class="rounded bg-white/10 border-white/25 text-brand-400 focus:ring-brand-400">
                <span class="text-sm text-brand-100/80">Show to employees</span>
            </label>

            {{-- Off by default, deliberately -- see the visible_to_clients
                 migration's own doc block. --}}
            <label class="inline-flex items-center min-h-[44px] gap-2">
                <input type="hidden" name="visible_to_clients" value="0">
                <input type="checkbox" name="visible_to_clients" value="1"
                       @checked(old('visible_to_clients', $announcement->visible_to_clients ?? false))
                       class="rounded bg-white/10 border-white/25 text-brand-400 focus:ring-brand-400">
                <span class="text-sm text-brand-100/80">Also show to clients</span>
            </label>
        </div>

        <x-primary-button>{{ $announcement ? 'Save Changes' : 'Post' }}</x-primary-button>
    </div>
</form>
