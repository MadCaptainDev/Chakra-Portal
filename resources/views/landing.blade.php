@php
    /*
    |---------------------------------------------------------------------------
    | FILL THESE IN
    |---------------------------------------------------------------------------
    | Everything below is business detail that could not be verified from the
    | codebase. Anything left as null simply does not render, so the page is
    | safe to ship half-filled - it just gets better as you complete it.
    |
    | The Work and Team sections are NOT here: they are managed from the admin
    | panel (Portfolio and Team), so the studio can publish without a deploy.
    */

    $contact = [
        'email' => null,      // 'hello@chakragroups.in'
        'phone' => null,      // '+91 98765 43210'
        'whatsapp' => null,   // '919876543210' - digits only, no + or spaces
        'address' => null,    // 'Chennai, Tamil Nadu'
    ];

    $social = [
        'Instagram' => null,  // 'https://instagram.com/...'
        'YouTube' => null,    // 'https://youtube.com/@...'
        'LinkedIn' => null,
    ];

    /*
    | Services. Drafted from the content pipeline this studio actually runs
    | (YouTube / Reels / Posts / Stories, scripting through to publishing).
    | Correct the wording to match how you sell it.
    */
    $services = [
        ['Short-form video', 'Reels, Shorts and stories cut for the feed - hook first, built to be watched with the sound off.'],
        ['YouTube long-form', 'Scripted episodes, interviews and series, with show notes and thumbnails handled end to end.'],
        ['Scripting & story', 'From a rough idea to a shooting script, so the shoot day has a plan instead of a vibe.'],
        ['Editing & post', 'Cut, colour, sound and captions. Consistent output whether it is one film or a monthly slate.'],
        ['Stills & static', 'Photography and designed posts for the grid, shot alongside the video so it all matches.'],
        ['Publishing & scheduling', 'We can take it all the way to posted and scheduled, not just hand over a folder of files.'],
    ];

    // Real: this is the pipeline every piece of content moves through in the studio.
    $process = [
        ['Idea', 'We agree the angle, the audience and what the piece has to do.'],
        ['Script', 'Written and signed off before anyone books a camera.'],
        ['Shoot', 'Planned shoot days against the script, so nothing is discovered on set.'],
        ['Edit', 'First cut, then revisions - you see it while it can still change cheaply.'],
        ['Review', 'Your notes, our final pass. Nothing ships without your sign-off.'],
        ['Publish', 'Delivered, scheduled and posted on the channels you want it on.'],
    ];
@endphp

<x-public-layout>
    {{-- Hero --}}
    <section class="relative overflow-hidden">
        {{-- The watermark already ships with the app; it doubles as hero texture. --}}
        <img src="{{ asset('images/chakra-watermark.png') }}" alt=""
             class="pointer-events-none select-none absolute -right-24 -top-16 w-[28rem] max-w-none opacity-[0.06]">

        <div class="relative max-w-7xl mx-auto px-5 sm:px-8 py-20 sm:py-28">
            <p class="text-brand-300 text-xs font-semibold uppercase tracking-[0.25em] mb-5">
                Chakra Productions
            </p>

            <h1 class="text-4xl sm:text-6xl lg:text-7xl font-extrabold leading-[1.05] max-w-4xl">
                We take video from
                <span class="text-brand-400">idea</span> to
                <span class="text-brand-400">posted</span>.
            </h1>

            <p class="mt-6 text-lg sm:text-xl text-brand-100/70 max-w-2xl leading-relaxed">
                A content studio for brands that need to publish consistently &mdash; scripted,
                shot, edited and scheduled by one team, on a pipeline that does not drop things.
            </p>

            <div class="mt-10 flex flex-col sm:flex-row gap-3">
                <a href="#contact"
                   class="inline-flex items-center justify-center min-h-[52px] px-8 rounded-md bg-brand-400 text-white text-sm font-semibold uppercase tracking-widest hover:bg-brand-500 transition-colors">
                    Start a project
                </a>
                <a href="{{ $portfolioItems->isNotEmpty() ? '#work' : '#process' }}"
                   class="inline-flex items-center justify-center min-h-[52px] px-8 rounded-md border border-white/20 text-white text-sm font-semibold uppercase tracking-widest hover:bg-white/5 transition-colors">
                    {{ $portfolioItems->isNotEmpty() ? 'See our work' : 'How we work' }}
                </a>
            </div>
        </div>
    </section>

    {{-- Services --}}
    <section id="services" class="bg-brand-800/40 border-y border-white/10 scroll-mt-20">
        <div class="max-w-7xl mx-auto px-5 sm:px-8 py-20 sm:py-24">
            <p class="text-brand-300 text-xs font-semibold uppercase tracking-[0.25em] mb-3">What we do</p>
            <h2 class="text-3xl sm:text-4xl font-bold max-w-2xl">Everything between the brief and the upload.</h2>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mt-12">
                @foreach ($services as [$title, $description])
                    <div class="rounded-xl bg-white/5 border border-white/10 p-6 hover:border-brand-400/40 transition-colors">
                        <h3 class="font-semibold text-lg mb-2">{{ $title }}</h3>
                        <p class="text-sm text-brand-100/60 leading-relaxed">{{ $description }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Work. Hidden entirely until something is published from the admin
         panel, so an empty grid can never reach a visitor. --}}
    @if ($portfolioItems->isNotEmpty())
        <section id="work" class="scroll-mt-20">
            <div class="max-w-7xl mx-auto px-5 sm:px-8 py-20 sm:py-24">
                <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4 mb-10">
                    <div>
                        <p class="text-brand-300 text-xs font-semibold uppercase tracking-[0.25em] mb-3">Selected work</p>
                        <h2 class="text-3xl sm:text-4xl font-bold max-w-2xl">Recent films and series.</h2>
                    </div>

                    @if ($hasMorePortfolio)
                        <a href="{{ route('portfolio') }}"
                           class="shrink-0 inline-flex items-center justify-center min-h-[48px] px-6 rounded-md border border-white/20 text-white text-sm font-semibold uppercase tracking-widest hover:bg-white/5 transition-colors">
                            See more
                        </a>
                    @endif
                </div>

                @include('partials.portfolio-grid', [
                    'categories' => $portfolioCategories,
                    'items' => $portfolioItems,
                ])

                <div class="mt-10 text-center">
                    <a href="{{ route('portfolio') }}"
                       class="inline-flex items-center justify-center min-h-[52px] px-8 rounded-md bg-brand-400 text-white text-sm font-semibold uppercase tracking-widest hover:bg-brand-500 transition-colors">
                        See the full portfolio
                    </a>
                </div>
            </div>
        </section>
    @endif

    {{-- Process --}}
    <section id="process" class="bg-brand-800/40 border-y border-white/10 scroll-mt-20">
        <div class="max-w-7xl mx-auto px-5 sm:px-8 py-20 sm:py-24">
            <p class="text-brand-300 text-xs font-semibold uppercase tracking-[0.25em] mb-3">How we work</p>
            <h2 class="text-3xl sm:text-4xl font-bold max-w-2xl">Six steps, and you see it at every one.</h2>
            <p class="mt-4 text-brand-100/60 max-w-2xl leading-relaxed">
                Every piece we make moves through the same pipeline, tracked from the first idea
                to the day it goes live. You always know which stage your project is sitting at.
            </p>

            <ol class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mt-12">
                @foreach ($process as $index => [$title, $description])
                    <li class="rounded-xl bg-white/5 border border-white/10 p-6">
                        <span class="text-brand-400/50 text-4xl font-extrabold leading-none">
                            {{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}
                        </span>
                        <h3 class="font-semibold text-lg mt-3 mb-2">{{ $title }}</h3>
                        <p class="text-sm text-brand-100/60 leading-relaxed">{{ $description }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- Team. Same rule as Work: nothing published, nothing rendered. --}}
    @if ($teamMembers->isNotEmpty())
        <section id="team" class="scroll-mt-20">
            <div class="max-w-7xl mx-auto px-5 sm:px-8 py-20 sm:py-24">
                <p class="text-brand-300 text-xs font-semibold uppercase tracking-[0.25em] mb-3">Who you work with</p>
                <h2 class="text-3xl sm:text-4xl font-bold max-w-2xl">The people behind the work.</h2>

                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4 sm:gap-5 mt-12">
                    @foreach ($teamMembers as $member)
                        <div class="rounded-xl bg-white/5 border border-white/10 overflow-hidden hover:border-brand-400/40 transition-colors">
                            <div class="aspect-square bg-brand-800/60 overflow-hidden">
                                @if ($member->photoUrl())
                                    <img src="{{ $member->photoUrl() }}" alt="{{ $member->name }}" loading="lazy"
                                         class="w-full h-full object-cover">
                                @else
                                    <div class="w-full h-full flex items-center justify-center text-3xl font-extrabold text-brand-300/50">
                                        {{ Str::of($member->name)->substr(0, 1)->upper() }}
                                    </div>
                                @endif
                            </div>
                            <div class="p-4">
                                <p class="font-semibold truncate">{{ $member->name }}</p>
                                @if ($member->role)
                                    <p class="text-xs text-brand-300 mt-0.5">{{ $member->role }}</p>
                                @endif
                                @if ($member->bio)
                                    <p class="text-sm text-brand-100/60 mt-2 leading-relaxed">{{ $member->bio }}</p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- Contact --}}
    <section id="contact" class="{{ $teamMembers->isNotEmpty() ? 'bg-brand-800/40 border-y border-white/10' : '' }} scroll-mt-20">
        <div class="max-w-7xl mx-auto px-5 sm:px-8 py-20 sm:py-24">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 lg:gap-16">
                <div>
                    <p class="text-brand-300 text-xs font-semibold uppercase tracking-[0.25em] mb-3">Get in touch</p>
                    <h2 class="text-3xl sm:text-4xl font-bold">Tell us what you want to make.</h2>
                    <p class="mt-4 text-brand-100/60 leading-relaxed">
                        A rough idea is enough to start. Tell us what it is for and roughly when
                        you need it, and we will come back with how we would approach it.
                    </p>

                    @if (array_filter($contact) || array_filter($social))
                        <div class="mt-10 space-y-3 text-sm">
                            @if ($contact['email'])
                                <p><a href="mailto:{{ $contact['email'] }}" class="text-brand-200 hover:text-white transition-colors">{{ $contact['email'] }}</a></p>
                            @endif
                            @if ($contact['phone'])
                                <p><a href="tel:{{ preg_replace('/[^0-9+]/', '', $contact['phone']) }}" class="text-brand-200 hover:text-white transition-colors">{{ $contact['phone'] }}</a></p>
                            @endif
                            @if ($contact['whatsapp'])
                                <p><a href="https://wa.me/{{ $contact['whatsapp'] }}" target="_blank" rel="noopener" class="text-brand-200 hover:text-white transition-colors">WhatsApp us</a></p>
                            @endif
                            @if ($contact['address'])
                                <p class="text-brand-100/50">{{ $contact['address'] }}</p>
                            @endif

                            @if (array_filter($social))
                                <div class="flex flex-wrap gap-4 pt-2">
                                    @foreach (array_filter($social) as $label => $url)
                                        <a href="{{ $url }}" target="_blank" rel="noopener"
                                           class="inline-flex items-center min-h-[44px] text-brand-200 hover:text-white transition-colors">
                                            {{ $label }}
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif
                </div>

                <div class="rounded-xl bg-white/5 border border-white/10 p-6 sm:p-8">
                    @if (session('status'))
                        <div class="mb-6 rounded-md bg-green-500/15 border border-green-400/30 px-4 py-3 text-sm text-green-100">
                            {{ session('status') }}
                        </div>
                    @endif

                    @if (session('error'))
                        <div class="mb-6 rounded-md bg-red-500/15 border border-red-400/30 px-4 py-3 text-sm text-red-100">
                            {{ session('error') }}
                        </div>
                    @endif

                    <form method="POST" action="{{ route('enquiry.store') }}" class="space-y-4">
                        @csrf

                        {{-- Honeypot: hidden from people, irresistible to bots. --}}
                        <div class="hidden" aria-hidden="true">
                            <label for="website">Website</label>
                            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                        </div>

                        {{-- Which page sent them here. The portfolio and case-study
                             CTAs link back with ?from=..., and anything that is not
                             one of the known pages is dropped server-side. --}}
                        @php
                            $from = (string) request()->query('from', '');
                            $source = old('source', isset(\App\Models\Enquiry::SOURCES[$from]) ? $from : 'landing');
                        @endphp
                        <input type="hidden" name="source" value="{{ $source }}">

                        <div>
                            <label for="name" class="block text-sm font-medium text-brand-100/80 mb-1.5">Your name</label>
                            <input type="text" id="name" name="name" value="{{ old('name') }}" required
                                   class="w-full min-h-[44px] rounded-md bg-brand-900/60 border-white/15 text-white placeholder-brand-100/30 focus:border-brand-400 focus:ring-brand-400">
                            @error('name') <p class="mt-1.5 text-sm text-red-300">{{ $message }}</p> @enderror
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="email" class="block text-sm font-medium text-brand-100/80 mb-1.5">Email</label>
                                <input type="email" id="email" name="email" value="{{ old('email') }}" required
                                       class="w-full min-h-[44px] rounded-md bg-brand-900/60 border-white/15 text-white placeholder-brand-100/30 focus:border-brand-400 focus:ring-brand-400">
                                @error('email') <p class="mt-1.5 text-sm text-red-300">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="phone" class="block text-sm font-medium text-brand-100/80 mb-1.5">
                                    Phone <span class="text-brand-100/40">(optional)</span>
                                </label>
                                <input type="text" id="phone" name="phone" value="{{ old('phone') }}"
                                       class="w-full min-h-[44px] rounded-md bg-brand-900/60 border-white/15 text-white placeholder-brand-100/30 focus:border-brand-400 focus:ring-brand-400">
                                @error('phone') <p class="mt-1.5 text-sm text-red-300">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div>
                            <label for="project" class="block text-sm font-medium text-brand-100/80 mb-1.5">What do you need?</label>
                            <select id="project" name="project"
                                    class="w-full min-h-[44px] rounded-md bg-brand-900/60 border-white/15 text-white focus:border-brand-400 focus:ring-brand-400">
                                <option value="">Not sure yet</option>
                                @foreach (['Short-form video', 'YouTube long-form', 'Full content retainer', 'Stills & static', 'Something else'] as $option)
                                    <option value="{{ $option }}" @selected(old('project') === $option)>{{ $option }}</option>
                                @endforeach
                            </select>
                            @error('project') <p class="mt-1.5 text-sm text-red-300">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="message" class="block text-sm font-medium text-brand-100/80 mb-1.5">About the project</label>
                            <textarea id="message" name="message" rows="5" required
                                      placeholder="What it is for, roughly when you need it, anything you already have."
                                      class="w-full rounded-md bg-brand-900/60 border-white/15 text-white placeholder-brand-100/30 focus:border-brand-400 focus:ring-brand-400">{{ old('message') }}</textarea>
                            @error('message') <p class="mt-1.5 text-sm text-red-300">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="prompted_by" class="block text-sm font-medium text-brand-100/80 mb-1.5">
                                What made you get in touch? <span class="text-brand-100/40">(optional)</span>
                            </label>
                            <input type="text" id="prompted_by" name="prompted_by" value="{{ old('prompted_by') }}"
                                   placeholder="A film you saw, a recommendation, something else"
                                   class="w-full min-h-[44px] rounded-md bg-brand-900/60 border-white/15 text-white placeholder-brand-100/30 focus:border-brand-400 focus:ring-brand-400">
                            @error('prompted_by') <p class="mt-1.5 text-sm text-red-300">{{ $message }}</p> @enderror
                        </div>

                        @error('website') <p class="text-sm text-red-300">{{ $message }}</p> @enderror

                        <button type="submit"
                                class="w-full inline-flex items-center justify-center min-h-[52px] px-8 rounded-md bg-brand-400 text-white text-sm font-semibold uppercase tracking-widest hover:bg-brand-500 transition-colors">
                            Send enquiry
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </section>
</x-public-layout>
