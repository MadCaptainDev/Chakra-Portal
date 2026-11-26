{{-- An editor's cancel or planner move, waiting for an admin. App\Support\VideoRequests. --}}
@if ($requests->isNotEmpty())
    <section id="requests" class="scroll-mt-24 rounded-2xl border border-amber-400/40 bg-[#15303f] p-4 sm:p-5">
        <h2 class="font-semibold text-white">⏳ Waiting for your approval <span class="ml-1 rounded-full bg-amber-400/20 px-2 text-xs text-amber-200">{{ $requests->count() }}</span></h2>
        <p class="mt-0.5 text-xs text-brand-100/55">Cancelling a video or moving it in the planner is your call. Approving does it straight away.</p>
        <ul class="mt-2 divide-y divide-white/5">
            @foreach ($requests as $q)
                <li class="flex flex-wrap items-center gap-x-3 gap-y-2 py-2.5" x-data="{ why: false }">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm text-white"><span class="font-semibold">{{ $q->user?->name }}</span> · {{ $q->summary() }}</p>
                        <p class="truncate text-xs text-brand-100/55">{{ $q->title }} · {{ $q->created_at->diffForHumans() }}</p>
                    </div>
                    @if (auth()->user()->isAdmin())
                        <form method="POST" action="{{ route('video-requests.approve', $q) }}">@csrf
                            <button class="h-10 rounded-lg bg-emerald-500 px-3.5 text-[13px] font-bold text-white hover:bg-emerald-400">Approve</button>
                        </form>
                        <button type="button" @click="why = !why" class="h-10 rounded-lg bg-white/10 px-3.5 text-[13px] font-semibold text-white hover:bg-white/15">Refuse</button>
                        <form x-show="why" x-cloak method="POST" action="{{ route('video-requests.refuse', $q) }}" class="flex w-full gap-2">@csrf
                            <input name="note" maxlength="250" placeholder="Why not? (optional)" class="h-10 min-w-0 flex-1 rounded-lg border-0 bg-[#0f2230] px-3 text-sm text-white placeholder:text-brand-100/40">
                            <button class="h-10 rounded-lg bg-red-500 px-3.5 text-[13px] font-bold text-white">Refuse</button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
@endif
