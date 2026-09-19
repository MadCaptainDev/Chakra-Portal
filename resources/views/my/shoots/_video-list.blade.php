{{-- What has been filed so far, newest last, the way the day ran. --}}
@if ($videos->isNotEmpty())
    <div class="mt-8 text-left">
        <p class="text-xs uppercase tracking-wide text-white/40">
            {{ $videos->count() }} {{ Str::plural('video', $videos->count()) }} so far
        </p>
        <ul class="mt-3 space-y-2">
            @foreach ($videos as $video)
                <li class="flex gap-3 rounded-xl bg-white/5 p-3">
                    @if ($video->photoUrl())
                        <img src="{{ $video->photoUrl() }}" alt=""
                             class="h-12 w-12 shrink-0 rounded-lg object-cover">
                    @else
                        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-white/5 text-xs text-white/30">
                            {{ $video->position }}
                        </div>
                    @endif
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">{{ $video->name }}</p>
                        @if ($video->notes)
                            <p class="mt-0.5 line-clamp-2 text-xs text-white/50">{{ $video->notes }}</p>
                        @endif
                        <p class="mt-0.5 text-xs text-white/30">
                            {{ $video->created_at->format('g:i A') }}
                            @if ($video->recordedBy) · {{ $video->recordedBy->name }} @endif
                        </p>
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
@endif
