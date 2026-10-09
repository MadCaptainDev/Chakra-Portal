{{-- Every proposal the studio has sent this client, newest first. Drafts
     never appear -- see Client\ProposalController::sentProposals(). Each row
     opens the full document: client/proposals-show.blade.php. --}}

<x-app-layout title="Proposals" dark>
    <div class="space-y-6">

        <div class="animate-rise-in">
            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-300">{{ $client->name }}</p>
            <h1 class="mt-2 text-3xl sm:text-4xl font-extrabold tracking-tight">Proposals</h1>
            <p class="mt-2 text-sm text-brand-100/70">What the studio has put together for you. Open one to read it, comment on any section, or download the PDF.</p>
        </div>

        @if ($proposals->isEmpty())
            <div class="rounded-xl border border-dashed border-white/15 px-6 py-12 text-center">
                <p class="text-sm text-brand-100/70">No proposals yet.</p>
                <p class="mt-1 text-xs text-brand-100/50">A proposal will show up here as soon as the studio sends you one.</p>
            </div>
        @else
            <div class="rounded-xl bg-white/5 ring-1 ring-white/10 overflow-hidden">
                @foreach ($proposals as $proposal)
                    <a href="{{ route('client.proposals.show', $proposal->id) }}"
                       class="flex items-center justify-between gap-3 p-4 sm:p-5 hover:bg-white/5 transition-colors {{ $loop->first ? '' : 'border-t border-white/10' }}">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="font-semibold text-white truncate">{{ $proposal->title }}</p>
                                <x-badge :status="$proposal->status" />
                            </div>
                            <p class="mt-1 text-xs text-brand-100/60">
                                Sent {{ ($proposal->token_issued_at ?? $proposal->updated_at)->format('j M Y') }}
                                @if ($proposal->valid_until) &middot; Valid until {{ $proposal->valid_until->format('j M Y') }} @endif
                                @if ($proposal->comments_count) &middot; {{ $proposal->comments_count }} {{ Str::plural('comment', $proposal->comments_count) }} @endif
                            </p>
                        </div>
                        <x-icon name="chevron-right" class="w-4 h-4 shrink-0 text-brand-100/40" />
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</x-app-layout>
