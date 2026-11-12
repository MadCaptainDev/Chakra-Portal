@php
    $tone = ['good_to_go' => 'bg-emerald-400/15 text-emerald-200', 'minor_fixes' => 'bg-amber-400/15 text-amber-200', 'needs_fixes' => 'bg-red-400/15 text-red-200'];
@endphp
<a href="{{ route('reviews.show', $v) }}" class="group flex gap-3 rounded-2xl border border-white/[0.06] bg-[#15303f] p-3 transition hover:-translate-y-0.5 hover:border-brand-400/40">
    <span class="flex h-24 w-16 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-black/40">
        @if ($v->poster_path && ! $v->deleted_file_at)
            <img src="{{ route('reviews.poster', $v) }}" alt="" loading="lazy" class="h-full w-full object-cover">
        @else
            <x-icon name="film-cut" class="h-6 w-6 text-white/30" />
        @endif
    </span>
    <span class="min-w-0 flex-1">
        <span class="block truncate text-[11px] text-brand-100/50">{{ $v->todo->contentItem?->venture ?? $v->todo->venture ?? 'Video' }} · {{ $v->todo->user?->name }}</span>
        <span class="mt-0.5 block line-clamp-2 text-sm font-semibold leading-snug text-white group-hover:text-brand-200">{{ $v->todo->title }}</span>
        <span class="mt-1.5 flex flex-wrap gap-1 text-[10.5px]">
            <span class="rounded bg-white/10 px-1.5 py-0.5 text-brand-100/80">v{{ $v->version }}</span>
            @if ($v->ai_verdict)
                <span class="rounded px-1.5 py-0.5 {{ $tone[$v->ai_verdict] ?? '' }}">AI: {{ $v->verdictLabel() }}</span>
            @elseif (in_array($v->ai_status, ['queued', 'running'], true))
                <span class="rounded bg-white/10 px-1.5 py-0.5 text-brand-100/60">AI checking…</span>
            @endif
            @if ($v->open_comments)
                <span class="rounded bg-white/10 px-1.5 py-0.5 text-brand-100/80">{{ $v->open_comments }} open</span>
            @endif
            @if ($v->status !== \App\Models\ReviewVideo::PENDING)
                <span class="rounded px-1.5 py-0.5 {{ $v->status === 'approved' ? 'bg-emerald-400/15 text-emerald-200' : 'bg-amber-400/15 text-amber-200' }}">{{ $v->statusLabel() }}</span>
            @endif
        </span>
        <span class="mt-1 block text-[11px] text-brand-100/40">{{ $v->created_at->diffForHumans() }}</span>
    </span>
</a>
