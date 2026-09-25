{{-- One comment thread in the admin sidebar: reply, resolve, reopen. --}}
<div id="comment-{{ $comment->id }}"
     class="rounded-lg ring-1 p-3 mb-3 {{ $comment->isResolved() ? 'bg-white/[0.02] ring-white/5 opacity-70' : 'bg-white/5 ring-white/10' }}"
     x-data="{ replying: false }">
    @if ($comment->section_key !== null && isset($labels[$comment->section_key]))
        <a href="#section-{{ $comment->section_key }}"
           class="block text-[11px] font-semibold uppercase tracking-wider text-brand-300 hover:text-brand-200 truncate">
            {{ $sectionName($comment->section_key) }}
        </a>
    @else
        <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-300">General feedback</p>
    @endif

    <p class="mt-1 text-xs text-brand-100/60">
        <span class="font-semibold text-white">{{ $comment->author_name }}</span>
        @if ($comment->isFromStaff()) <span class="text-brand-300">(team)</span>
        @elseif ($comment->author_email) · <a href="mailto:{{ $comment->author_email }}" class="hover:text-brand-200">{{ $comment->author_email }}</a>
        @endif
        · {{ $comment->created_at->format('j M, g:i A') }}
    </p>
    <p class="mt-1 text-sm text-brand-100 whitespace-pre-line">{{ $comment->body }}</p>

    @foreach ($comment->replies as $reply)
        <div class="mt-2 pl-3 border-l-2 {{ $reply->isFromStaff() ? 'border-brand-400' : 'border-white/20' }}">
            <p class="text-xs text-brand-100/60">
                <span class="font-semibold text-white">{{ $reply->author_name }}</span>
                @if ($reply->isFromStaff()) <span class="text-brand-300">(team)</span> @endif
                · {{ $reply->created_at->format('j M, g:i A') }}
            </p>
            <p class="text-sm text-brand-100 whitespace-pre-line">{{ $reply->body }}</p>
        </div>
    @endforeach

    @if ($comment->isResolved())
        <p class="mt-2 text-xs text-emerald-300/80">
            Resolved{{ $comment->resolvedBy ? ' by '.$comment->resolvedBy->name : '' }} {{ $comment->resolved_at->diffForHumans() }}
        </p>
    @endif

    @if ($canComment)
        <div class="mt-2 flex flex-wrap items-center gap-1">
            <button type="button" class="min-h-[36px] px-2 rounded-md text-xs font-semibold text-brand-300 hover:bg-white/10"
                    @click="replying = !replying" x-show="!replying">Reply</button>
            @if ($comment->isResolved())
                <form method="POST" action="{{ route('proposals.comments.reopen', [$proposal, $comment]) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="min-h-[36px] px-2 rounded-md text-xs font-semibold text-brand-100/70 hover:bg-white/10">Reopen</button>
                </form>
            @else
                <form method="POST" action="{{ route('proposals.comments.resolve', [$proposal, $comment]) }}">
                    @csrf
                    <button type="submit" class="min-h-[36px] px-2 rounded-md text-xs font-semibold text-emerald-300 hover:bg-white/10">Resolve</button>
                </form>
            @endif
        </div>

        <form method="POST" action="{{ route('proposals.comments.store', $proposal) }}" x-show="replying" x-cloak class="mt-2 space-y-2">
            @csrf
            <input type="hidden" name="parent_id" value="{{ $comment->id }}">
            <textarea name="body" rows="3" required maxlength="5000"
                      class="w-full bg-white/5 border-white/15 text-white placeholder-brand-100/40 focus:border-brand-400 focus:ring-brand-400 rounded-md text-sm"
                      placeholder="Your reply — the client sees it on their link"></textarea>
            <div class="flex justify-end gap-2">
                <x-btn type="button" variant="ghost" size="sm" @click="replying = false">Cancel</x-btn>
                <x-btn type="submit" size="sm">Reply</x-btn>
            </div>
        </form>
    @endif
</div>
