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
                        <div x-data="{ editing: false }">
                            <div x-show="!editing" class="flex items-center gap-2">
                                <p class="min-w-0 flex-1 truncate text-sm font-medium">{{ $video->name }}</p>
                                <button type="button" @click="editing = true; $nextTick(() => $refs.name.select())"
                                        class="shrink-0 rounded-md px-2 py-1 text-xs text-white/50 hover:bg-white/10 hover:text-white" aria-label="Rename this video">Edit</button>
                            </div>
                            <form x-show="editing" x-cloak method="POST" action="{{ route('my.shoots.videos.rename', [$video->shoot_id, $video]) }}" class="flex items-center gap-2">
                                @csrf @method('PATCH')
                                <input type="text" name="name" x-ref="name" value="{{ $video->name }}" required maxlength="160"
                                       @keydown.escape="editing = false"
                                       class="min-w-0 flex-1 rounded-lg border-white/15 bg-white/10 px-2.5 py-1.5 text-sm text-white focus:border-amber-400 focus:ring-amber-400">
                                <button type="submit" class="shrink-0 rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-black">Save</button>
                                <button type="button" @click="editing = false" class="shrink-0 text-xs text-white/50">Cancel</button>
                            </form>
                        </div>
                        @if ($video->script)
                            <p class="mt-0.5 truncate text-xs text-amber-200/70">Script #{{ $video->script->id }}: {{ $video->script->title }}</p>
                        @endif
                        @if ($video->sectionSummary())
                            <p class="mt-0.5 text-xs text-white/50">{{ $video->sectionSummary() }}</p>
                        @endif
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
