<x-app-layout title="My Routines">
    <x-slot name="header">
        <x-page-header title="My Routines" eyebrow="Your work"
                       subtitle="Tick what you have done, then save once. Anything late stays until it is done." />
    </x-slot>

    <div class="max-w-3xl space-y-6">
        @foreach ($inboxChecks as $check)
            <a href="{{ route('inbox-desk.index') }}"
               class="group flex items-center gap-4 rounded-2xl p-4 ring-1 transition
                      {{ $check['late'] > 0 ? 'bg-amber-400/10 ring-amber-400/30' : 'bg-gradient-to-br from-brand-400/15 to-white/5 ring-white/10' }}
                      hover:ring-white/25">
                <span class="w-11 h-11 shrink-0 rounded-xl bg-gradient-to-br from-fuchsia-500 via-rose-500 to-amber-400 text-white flex items-center justify-center">
                    <x-icon name="inbox" class="w-5 h-5" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block font-semibold text-white">{{ $check['title'] }}</span>
                    <span class="block text-xs mt-0.5 {{ $check['late'] > 0 ? 'text-amber-200' : 'text-brand-100/70' }}">
                        {{ $check['left'] }} {{ Str::plural('check', $check['left']) }} left
                        @if ($check['late'] > 0) &middot; {{ $check['late'] }} late @endif
                        &middot; open the Inbox Check
                    </span>
                </span>
                <x-icon name="chevron-right" class="w-5 h-5 text-brand-100/50 group-hover:text-white shrink-0" />
            </a>
        @endforeach

        @if ($tasks->isEmpty() && $inboxChecks->isNotEmpty())
            {{-- Only the Inbox Check is owed; nothing else to say. --}}
        @elseif ($tasks->isEmpty())
            <x-card padding="sm">
                <p class="text-sm text-brand-100/60">Nothing due. You are all caught up.</p>
            </x-card>
        @else
            <form method="POST" action="{{ route('my.routines.complete-many') }}" x-data="{ ticked: [] }">
                @csrf

                <div class="space-y-2">
                    @foreach ($tasks as $task)
                        @include('my._routine-task', ['task' => $task])
                    @endforeach
                </div>

                <div class="mt-4 flex items-center justify-between gap-3">
                    <p class="text-xs text-brand-100/60" x-show="ticked.length" x-cloak>
                        <span x-text="ticked.length"></span> selected
                    </p>
                    <x-btn type="submit" x-bind:disabled="! ticked.length" class="ml-auto">
                        Save
                    </x-btn>
                </div>
            </form>
        @endif

        @if ($upcoming->isNotEmpty())
            <section>
                <h2 class="text-sm font-semibold uppercase tracking-wider text-brand-100/60 mb-2">Coming up</h2>
                <x-card padding="sm" class="divide-y divide-white/10">
                    @foreach ($upcoming as $row)
                        <div class="py-2 first:pt-0 last:pb-0 flex items-baseline justify-between gap-3">
                            <p class="text-sm text-brand-100/80 min-w-0 truncate">{{ $row['routine']->title }}</p>
                            <p class="text-xs text-brand-100/60 shrink-0">
                                {{ $row['dates']->map(fn ($d) => $d->format('D j M'))->implode(' · ') }}
                            </p>
                        </div>
                    @endforeach
                </x-card>
            </section>
        @endif
    </div>
</x-app-layout>
