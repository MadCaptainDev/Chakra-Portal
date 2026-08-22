@props(['title' => null])

{{--
    One "Settings" destination in the sidebar, tabbed rather than nine
    separate links -- each tab is still its own real page (own controller,
    own form, own Alpine state), just wrapped in a shared strip so moving
    between them reads as one screen instead of nine unrelated ones.

    Full width on purpose: several of these used to sit in a narrow
    max-w-2xl/3xl centered card, which read as cramped next to every other
    admin screen in the app running the full max-w-7xl x-app-layout gives
    everything else. This component's own slot renders at that same width.
--}}

<x-app-layout :title="$title">
    @isset($header)
        <x-slot name="header">{{ $header }}</x-slot>
    @endisset

    @php
        // Order matches the old Setup section of the sidebar. Each entry is
        // skipped if its route was never registered (a settings screen this
        // deploy doesn't have), same guard the sidebar used to apply itself.
        $settingsTabs = [
            'settings.edit' => 'Company',
            'whatsapp.edit' => 'WhatsApp',
            'instagram-settings.edit' => 'Instagram',
            'notion.edit' => 'Notion',
            'push.edit' => 'Notifications',
            'competitor-settings.edit' => 'Competitor Analysis',
            'content-accounts.edit' => 'Content Accounts',
            'brief-questions.index' => 'Brief Questions',
            'invoice-template.edit' => 'PDF Template',
        ];
    @endphp

    <div class="mb-6 -mt-1 border-b border-gray-200 overflow-x-auto">
        <nav class="flex gap-1 min-w-max">
            @foreach ($settingsTabs as $routeName => $label)
                @continue(! Route::has($routeName))
                @php $module = explode('.', $routeName)[0]; @endphp
                <a href="{{ route($routeName) }}"
                   class="px-3.5 py-2.5 text-sm font-semibold border-b-2 whitespace-nowrap transition-colors
                          {{ request()->routeIs($module.'.*')
                              ? 'border-brand-500 text-brand-700'
                              : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
                    {{ $label }}
                </a>
            @endforeach
        </nav>
    </div>

    {{ $slot }}
</x-app-layout>
