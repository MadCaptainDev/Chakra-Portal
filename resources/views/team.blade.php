{{-- The public Team page: the people switched on in Website → Team page. --}}
<x-public-layout title="Our Team | Chakra Productions, Trichy"
                 description="Meet the writers, camera crew, editors and strategists at Chakra Productions — the people behind the reels.">
    <section class="relative overflow-hidden border-b border-white/10 pt-28 pb-14 sm:pt-36 sm:pb-20">
        <div class="pointer-events-none absolute -top-24 right-[-10%] h-80 w-80 rounded-full bg-brand-400/15 blur-3xl"></div>
        <div class="mx-auto max-w-7xl px-5 sm:px-8">
            <p class="mb-3 text-xs font-semibold uppercase tracking-[0.25em] text-brand-300">The team</p>
            <h1 class="max-w-3xl text-4xl font-extrabold leading-[1.05] sm:text-6xl">The people behind the work.</h1>
            <p class="mt-5 max-w-2xl text-lg text-brand-100/75">Script, shoot, edit and post — one team in Trichy, from the first idea to the reel going live.</p>
        </div>
    </section>

    <section class="py-14 sm:py-20">
        <div class="mx-auto grid max-w-7xl grid-cols-2 gap-4 px-5 sm:grid-cols-3 sm:gap-6 sm:px-8 lg:grid-cols-4">
            @foreach ($team as $member)
                <article class="group">
                    <div class="relative aspect-[4/5] overflow-hidden rounded-2xl bg-brand-800 ring-1 ring-white/10">
                        @if ($member->photoUrl())
                            <img src="{{ $member->photoUrl() }}" alt="{{ $member->name }}" loading="lazy" class="h-full w-full object-cover transition duration-700 group-hover:scale-105">
                        @else
                            <div class="flex h-full w-full items-center justify-center bg-gradient-to-br from-brand-700 to-brand-900 text-6xl font-extrabold text-brand-300/50">{{ mb_strtoupper(mb_substr($member->name, 0, 1)) }}</div>
                        @endif
                        <div class="absolute inset-0 bg-gradient-to-t from-brand-900/90 via-transparent to-transparent"></div>
                        <div class="absolute inset-x-0 bottom-0 p-3 sm:p-4">
                            <p class="text-base font-semibold leading-tight sm:text-lg">{{ $member->name }}</p>
                            @if ($member->role)<p class="mt-0.5 text-xs text-brand-300 sm:text-sm">{{ $member->role }}</p>@endif
                        </div>
                    </div>
                    @if ($member->bio)
                        <p class="mt-3 text-sm leading-relaxed text-brand-100/65">{{ $member->bio }}</p>
                    @endif
                </article>
            @endforeach
        </div>
    </section>

    <section class="border-t border-white/10 py-14 sm:py-20">
        <div class="mx-auto max-w-3xl px-5 text-center sm:px-8">
            <h2 class="text-3xl font-bold sm:text-4xl">Want this team on your brand?</h2>
            <p class="mt-3 text-brand-100/75">Tell us about your business — we usually reply within an hour.</p>
            <div class="mt-7 flex flex-wrap justify-center gap-3">
                @if (config('studio.whatsapp'))
                    <a href="https://wa.me/{{ config('studio.whatsapp') }}?text={{ rawurlencode("Hi Chakra Productions! 👋 I saw your team page and I'd like reels for my business.\n\n— via chakragroups.in") }}"
                       target="_blank" rel="noopener" data-track="whatsapp" data-track-target="team_cta" data-track-label="Team page button"
                       class="inline-flex min-h-[52px] items-center gap-2 rounded-full bg-[#25D366] px-6 font-bold text-white shadow-lg transition hover:scale-105">💬 Chat on WhatsApp</a>
                @endif
                <a href="{{ route('portfolio') }}" class="inline-flex min-h-[52px] items-center rounded-full border border-white/20 px-6 font-semibold text-white transition hover:bg-white/5">See our work</a>
            </div>
        </div>
    </section>
</x-public-layout>
