@php
    use App\Models\Proposal;

    $open = $comments->filter(fn ($c) => ! $c->isResolved());
    $resolved = $comments->filter(fn ($c) => $c->isResolved());

    // Open client threads per section, for the count badge on each heading.
    $openFromClient = $open->filter(fn ($c) => ! $c->isFromStaff());
    $commentCounts = $openFromClient->countBy(fn ($c) => $c->section_key ?? '')->all();
    $commentAnchors = $openFromClient->groupBy(fn ($c) => $c->section_key ?? '')
        ->map(fn ($group) => 'comment-'.$group->first()->id)
        ->all();

    $sectionName = fn (?string $key) => $key !== null && isset($labels[$key]) ? $labels[$key] : 'General feedback';
    $canComment = auth()->user()->can('proposals.comment');
@endphp

<x-app-layout :title="$proposal->title">
    @push('styles')
        @vite('resources/css/proposal.css')
    @endpush

    <x-slot name="header">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-widest text-brand-300">Proposal</p>
                <div class="flex flex-wrap items-center gap-3">
                    <h2 class="font-semibold text-xl text-white leading-tight">{{ $proposal->title }}</h2>
                    <x-badge :status="$proposal->status" />
                </div>
                <p class="text-sm text-brand-100/60">
                    {{ $proposal->client?->name ?? 'No client linked' }}
                    @if ($proposal->valid_until) · valid until {{ $proposal->valid_until->format('j M Y') }} @endif
                    @if ($proposal->createdBy) · by {{ $proposal->createdBy->name }} @endif
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-btn :href="route('proposals.pdf', $proposal)" variant="secondary" size="sm">Download PDF</x-btn>
                @can('proposals.edit')
                    <x-btn :href="route('proposals.edit', $proposal)" size="sm">Edit</x-btn>
                @endcan
                @can('proposals.create')
                    <form method="POST" action="{{ route('proposals.duplicate', $proposal) }}">
                        @csrf
                        <x-btn type="submit" variant="secondary" size="sm">Duplicate</x-btn>
                    </form>
                @endcan
                @can('proposals.delete')
                    <form method="POST" action="{{ route('proposals.destroy', $proposal) }}"
                          onsubmit="return confirm('Delete this proposal, its link and all its comments?');">
                        @csrf
                        @method('DELETE')
                        <x-btn type="submit" variant="ghost" size="sm" class="!text-red-300">Delete</x-btn>
                    </form>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_22rem] gap-6 items-start">
        <div class="min-w-0">
            @include('proposals._document', [
                'proposal' => $proposal,
                'settings' => $settings,
                'mode' => 'admin',
                'commentCounts' => $commentCounts,
                'commentAnchors' => $commentAnchors,
            ])
        </div>

        <aside class="space-y-4 xl:sticky xl:top-20">
            {{-- Share link --}}
            <x-card class="p-4 space-y-3">
                <h3 class="font-semibold text-white">Client link</h3>

                @if ($proposal->public_token)
                    <div x-data="{ copied: false }" class="space-y-2">
                        <input type="text" readonly value="{{ $proposal->publicUrl() }}" x-ref="url"
                               class="w-full bg-white/5 border-white/15 text-white rounded-md min-h-[44px] text-xs font-mono"
                               @focus="$el.select()">
                        <div class="flex flex-wrap gap-2">
                            <x-btn type="button" size="sm"
                                   @click="navigator.clipboard?.writeText($refs.url.value).then(() => { copied = true; setTimeout(() => copied = false, 2000) }).catch(() => $refs.url.select())">
                                <span x-show="!copied">Copy link</span>
                                <span x-show="copied" x-cloak>Copied</span>
                            </x-btn>
                            <x-btn :href="$proposal->publicUrl()" variant="secondary" size="sm" target="_blank" rel="noopener">Open</x-btn>
                        </div>
                    </div>
                    <p class="text-xs text-brand-100/60">
                        Created {{ $proposal->token_issued_at?->diffForHumans() }}.
                        {{ $proposal->first_viewed_at ? 'First opened by the client '.$proposal->first_viewed_at->diffForHumans().'.' : 'Not opened by the client yet.' }}
                    </p>
                    @can('proposals.edit')
                        <div class="flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('proposals.link', $proposal) }}"
                                  onsubmit="return confirm('Make a new link? The current one stops working immediately.');">
                                @csrf
                                <x-btn type="submit" variant="ghost" size="sm">New link</x-btn>
                            </form>
                            <form method="POST" action="{{ route('proposals.link.revoke', $proposal) }}"
                                  onsubmit="return confirm('Close this link? The client will no longer be able to open it.');">
                                @csrf
                                @method('DELETE')
                                <x-btn type="submit" variant="ghost" size="sm" class="!text-red-300">Close link</x-btn>
                            </form>
                        </div>
                    @endcan
                @else
                    <p class="text-sm text-brand-100/70">No link yet. Create one to send the client — they can read the proposal, comment on any section and download the PDF, without logging in.</p>
                    @can('proposals.edit')
                        <form method="POST" action="{{ route('proposals.link', $proposal) }}">
                            @csrf
                            <x-btn type="submit" size="sm">Create link</x-btn>
                        </form>
                    @endcan
                @endif

                @can('proposals.edit')
                    <form method="POST" action="{{ route('proposals.status', $proposal) }}" class="flex items-center gap-2 pt-2 border-t border-white/10">
                        @csrf
                        @method('PATCH')
                        <label for="status" class="text-xs text-brand-100/70 shrink-0">Status</label>
                        <x-select id="status" name="status" class="flex-1 !min-h-[40px] text-sm" onchange="this.form.submit()">
                            @foreach (Proposal::STATUSES as $value => $label)
                                <option value="{{ $value }}" @selected($proposal->status === $value)>{{ $label }}</option>
                            @endforeach
                        </x-select>
                        <noscript><x-btn type="submit" size="sm">Set</x-btn></noscript>
                    </form>
                @endcan
            </x-card>

            {{-- Comments --}}
            <x-card class="p-4" x-data="{ showResolved: false }">
                <div class="flex items-center justify-between gap-2 mb-3">
                    <h3 class="font-semibold text-white">Comments</h3>
                    <span class="text-xs text-brand-100/60">{{ $open->count() }} open</span>
                </div>

                @forelse ($open as $comment)
                    @include('proposals._admin-thread', ['comment' => $comment])
                @empty
                    <p class="text-sm text-brand-100/60">No open comments. When the client comments on their link, it shows up here and in your notifications.</p>
                @endforelse

                @if ($resolved->isNotEmpty())
                    <button type="button" class="mt-3 text-xs font-semibold text-brand-300 hover:text-brand-200 min-h-[44px]"
                            @click="showResolved = !showResolved"
                            x-text="showResolved ? 'Hide resolved' : 'Show {{ $resolved->count() }} resolved'"></button>
                    <div x-show="showResolved" x-cloak class="space-y-3 mt-2">
                        @foreach ($resolved as $comment)
                            @include('proposals._admin-thread', ['comment' => $comment])
                        @endforeach
                    </div>
                @endif
            </x-card>
        </aside>
    </div>
</x-app-layout>
