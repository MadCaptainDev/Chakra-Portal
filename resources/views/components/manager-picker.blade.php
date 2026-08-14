@props([
    'managers' => [],
    'selected' => [],
])

@php
    // More than one manager is normal here -- an editor answers to the producer
    // on the job and to the studio lead on the week -- so this is a tick list
    // rather than the single select it replaced.
    $selected = collect($selected)->map(fn ($id) => (int) $id)->all();
@endphp

<div x-data="{ query: '' }">
    <x-input-label value="Reports to" />

    <p class="text-xs text-gray-500 mt-1 mb-2">
        Any of these can decide this person&rsquo;s days and validate their to-dos, and each gets
        the team screens without anything else being ticked. Tick nobody and approvals fall to
        the admins.
    </p>

    {{-- No hidden fallback field: unticking everybody sends nothing, and the
         controller reads that as an empty list rather than "leave it alone".
         A hidden name="manager_ids" would arrive as a string and fail the array
         rule the moment somebody cleared the list. --}}
    @if (count($managers) > 6)
        <input type="search" x-model="query" placeholder="Find a name…"
               class="mb-2 w-full rounded-md border-gray-300 shadow-sm focus:border-brand-400 focus:ring-brand-400 text-sm min-h-[44px]">
    @endif

    <div class="max-h-56 overflow-y-auto rounded-lg ring-1 ring-gray-900/10 divide-y divide-gray-100 bg-white">
        @forelse ($managers as $manager)
            <label class="flex items-center gap-3 px-3 py-2.5 min-h-[44px] cursor-pointer transition-colors hover:bg-gray-50"
                   x-show="query === '' || @js(mb_strtolower($manager->name)).includes(query.toLowerCase())">
                <input type="checkbox" name="manager_ids[]" value="{{ $manager->id }}"
                       @checked(in_array((int) $manager->id, $selected, true))
                       class="rounded border-gray-300 text-brand-500 shadow-sm focus:ring-brand-400">
                <x-avatar :name="$manager->name" size="sm" />
                <span class="text-sm text-gray-700 truncate">{{ $manager->name }}</span>
            </label>
        @empty
            <p class="px-3 py-3 text-sm text-gray-500">There is nobody else to report to yet.</p>
        @endforelse
    </div>

    <x-input-error class="mt-2" :messages="$errors->get('manager_ids')" />
    <x-input-error class="mt-2" :messages="$errors->get('manager_ids.*')" />
</div>
