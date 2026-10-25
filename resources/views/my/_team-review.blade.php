{{--
    The rest of the team's videos in review (EditingController::teamReview):
    done, waiting for a correction or Video ready. To see, not to act on --
    the review itself happens on the editor's own card and on Editor Updates.
--}}
@if ($teamReview)
    <details class="group rounded-2xl border border-sky-400/25 bg-[#15303f]" @if (count($teamReview) <= 4) open @endif>
        <summary class="flex cursor-pointer list-none items-center justify-between gap-2 px-4 py-3 sm:px-5">
            <h2 class="font-semibold text-white">In review · team <span class="ml-1 rounded-full bg-sky-400/20 px-2 text-xs text-sky-200">{{ count($teamReview) }}</span></h2>
            <span class="text-xs text-brand-100/50 group-open:hidden">Show</span>
            <span class="hidden text-xs text-brand-100/50 group-open:inline">Hide</span>
        </summary>
        <ul class="space-y-1.5 px-3 pb-3 sm:px-4">
            @foreach ($teamReview as $t)
                <li class="flex items-center gap-3 rounded-xl bg-white/[0.04] px-3 py-2.5">
                    @if ($t['user']['avatar_url'] ?? null)
                        <img src="{{ $t['user']['avatar_url'] }}" alt="" class="h-8 w-8 shrink-0 rounded-full object-cover">
                    @else
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-sky-400/20 text-xs font-semibold text-sky-200">{{ Str::substr($t['editor'] ?? '?', 0, 1) }}</span>
                    @endif
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-white">{{ $t['title'] }}</p>
                        <p class="truncate text-xs text-brand-100/55">{{ $t['editor'] }}{{ $t['client'] ? ' · '.$t['client'] : '' }} · waiting {{ $t['waiting'] }}</p>
                        @if ($t['sent_back'])
                            <p class="truncate text-xs text-red-200/80">Sent back: {{ $t['sent_back'] }}</p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </details>
@endif
