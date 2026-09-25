<x-app-layout title="Proposals">
    <x-slot name="header">
        <x-page-header title="Proposals" eyebrow="Finance"
                       subtitle="Designed proposals, the links clients read them on, and what they said.">
            @can('proposals.create')
                <x-slot name="actions">
                    <x-btn :href="route('proposals.create')" icon="plus">New proposal</x-btn>
                </x-slot>
            @endcan
        </x-page-header>
    </x-slot>

    <div class="space-y-4">
        <x-filter-bar>
            <div class="flex flex-wrap gap-2">
                @foreach (['' => 'All'] + \App\Models\Proposal::STATUSES as $value => $label)
                    <a href="{{ route('proposals.index', array_filter(['status' => $value, 'search' => $search])) }}"
                       class="px-3 py-1.5 rounded-lg text-xs font-semibold {{ $status === $value ? 'bg-brand-400 text-brand-900' : 'bg-white/10 text-brand-100/70 hover:bg-white/[0.16]' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>

            <form method="GET" action="{{ route('proposals.index') }}">
                <input type="hidden" name="status" value="{{ $status }}">
                <input type="search" name="search" value="{{ $search }}" placeholder="Search by proposal or client name..."
                       class="w-full sm:max-w-md rounded-lg border-white/15 bg-white/5 text-white shadow-sm focus:border-brand-400 focus:ring-brand-400 min-h-[44px]">
            </form>
        </x-filter-bar>

        @if ($proposals->isEmpty())
            <x-empty-state message="No proposals{{ $status || $search ? ' for this filter' : ' yet' }}.">
                @can('proposals.create')
                    <x-btn :href="route('proposals.create')" icon="plus" size="sm">Create a proposal</x-btn>
                @endcan
            </x-empty-state>
        @else
            <div class="space-y-3">
                @foreach ($proposals as $proposal)
                    <a href="{{ route('proposals.show', $proposal) }}"
                       class="block rounded-xl bg-white/5 ring-1 ring-white/10 p-4 hover:bg-white/[0.08] hover:ring-white/20 transition">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-semibold text-white truncate">{{ $proposal->title }}</p>
                                <p class="text-sm text-brand-100/60">
                                    {{ $proposal->client?->name ?? 'No client linked' }}
                                    · updated {{ $proposal->updated_at->diffForHumans() }}
                                </p>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                @if ($proposal->open_comments_count > 0)
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-400/15 text-amber-200"
                                          title="Client comments not yet resolved">
                                        {{ $proposal->open_comments_count }} open {{ Str::plural('comment', $proposal->open_comments_count) }}
                                    </span>
                                @endif
                                @if ($proposal->public_token)
                                    <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-brand-400/20 text-brand-200">Link live</span>
                                @endif
                                <x-badge :status="$proposal->status" />
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>

            {{ $proposals->links() }}
        @endif
    </div>
</x-app-layout>
