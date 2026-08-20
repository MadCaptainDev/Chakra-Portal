@php
    use App\Support\BrandBrief;

    /*
    | The brand brief wizard, shared by the portal and the public link.
    |
    | A port of the design's Onboarding.jsx: seven steps, a clickable rail over
    | a progress bar, a review screen, and a success screen -- in the portal's
    | dark palette rather than the kit's light one.
    |
    | Everything is ONE form. Steps are shown and hidden by Alpine rather than
    | posted per step, so the client can jump back to step two from the review
    | without a round trip, and one submit carries every answer.
    |
    | Required by the caller:
    |   $brief      ClientBrief (may not exist yet)
    |   $saveUrl    where autosave and "save & exit" post
    |   $submitUrl  where the final submit posts
    |   $exitUrl    where "save & exit" sends them afterwards, or null
    |   $showName   whether to ask who is filling it in (public link only)
    */
    $answers = $brief->exists ? $brief->answerMap() : [];
    // The seven shared groups, plus this client's own group when they have
    // one. The controller has already said whose brief this is.
    $steps = BrandBrief::stepsForClient();
    $answered = $brief->exists ? $brief->requiredAnswered() : 0;
    $total = $brief->exists ? $brief->requiredTotal() : count(BrandBrief::requiredKeys());
@endphp

<div x-data="briefWizard({
        steps: {{ count($steps) }},
        saveUrl: @js($saveUrl),
        answered: {{ $answered }},
        total: {{ $total }},
        startAt: {{ (int) old('_step', 0) }},
        titles: @js(array_column($steps, 'title')),
     })"
     x-init="init()"
     class="mx-auto max-w-3xl">

    {{-- Step rail. Completed steps stay clickable so a client can go back and
         fix an answer; steps ahead are inert until reached. Collapses to the
         bar alone on narrow screens, as the design specifies. --}}
    <div class="rounded-2xl bg-white/5 ring-1 ring-white/10 p-3.5 sm:p-4 mb-4">
        <div class="flex flex-wrap items-baseline justify-between gap-2 mb-3">
            <p class="text-sm font-semibold text-white" x-text="reviewing ? 'Final review' : stepTitle"></p>
            <p class="text-xs text-brand-100/60"
               x-text="reviewing ? 'All {{ count($steps) }} steps saved' : `Step ${step + 1} of {{ count($steps) }}`"></p>
        </div>

        <div class="hidden sm:flex flex-wrap items-center gap-1.5 mb-2.5" x-show="! reviewing">
            @foreach ($steps as $i => $step)
                @if ($i > 0)
                    <span class="text-white/20"><x-icon name="chevron-right" class="w-3.5 h-3.5" /></span>
                @endif
                <button type="button" @click="goTo({{ $i }})"
                        :disabled="{{ $i }} > furthest"
                        class="inline-flex items-center gap-1.5 px-0.5 py-1 text-xs font-semibold transition-colors disabled:cursor-default"
                        :class="step === {{ $i }} ? 'text-brand-200'
                              : ({{ $i }} < furthest || done.includes({{ $i }}) ? 'text-brand-100/70 hover:text-white' : 'text-brand-100/30')">
                    <span class="w-[18px] h-[18px] rounded-full flex items-center justify-center text-[10px] font-bold shrink-0"
                          :class="step === {{ $i }} ? 'bg-brand-400 text-brand-900'
                                : (done.includes({{ $i }}) ? 'bg-brand-400/25 text-brand-200' : 'bg-white/10 text-brand-100/40')">
                        <template x-if="done.includes({{ $i }}) && step !== {{ $i }}">
                            <svg class="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        </template>
                        <template x-if="! (done.includes({{ $i }}) && step !== {{ $i }})">
                            <span>{{ $i + 1 }}</span>
                        </template>
                    </span>
                    {{ $step['label'] }}
                </button>
            @endforeach
        </div>

        <div class="h-1 rounded-full bg-white/10 overflow-hidden">
            <div class="h-full rounded-full bg-brand-400 transition-[width] duration-300" :style="`width: ${pct}%`"></div>
        </div>
    </div>

    <form method="POST" action="{{ $submitUrl }}" x-ref="form" @submit="dirty = false">
        @csrf

        {{-- Which step to reopen on if the server bounces this back with
             errors. Without it a validation failure dumps the client on step
             one with no idea which answer was refused. --}}
        <input type="hidden" name="_step" :value="step">

        @foreach ($steps as $i => $step)
            <div x-show="! reviewing && step === {{ $i }}" x-cloak
                 class="rounded-2xl bg-white/5 ring-1 ring-white/10 p-5 sm:p-6 grid gap-7">
                <div>
                    <h2 class="text-xl font-bold tracking-tight text-white">{{ $step['title'] }}</h2>
                    <p class="mt-1 text-sm text-brand-100/60">{{ $step['blurb'] }}</p>
                </div>

                @foreach ($step['questions'] as $key => $q)
                    @php $visible = BrandBrief::isVisible($key, $answers); @endphp
                    {{-- Conditional questions are rendered but hidden, and the
                         condition is re-evaluated live by Alpine: a client who
                         answers "Yes" must see the follow-up immediately, not
                         after a save. --}}
                    <div @if (isset($q['showIf']))
                            x-show="answers[@js($q['showIf'][0])] === @js($q['showIf'][1])"
                            x-cloak
                         @endif>
                        @include('brief._question', ['key' => $key, 'q' => $q, 'brief' => $brief])
                    </div>
                @endforeach
            </div>
        @endforeach

        {{-- Review. Every step, every answer, each with a way back to it. --}}
        <div x-show="reviewing" x-cloak class="grid gap-4">
            <div class="rounded-2xl bg-white/5 ring-1 ring-white/10 p-5">
                <h2 class="text-lg font-bold text-white">Review your information</h2>
                <p class="mt-1 text-sm text-brand-100/60">
                    Check anything that looks off before it reaches the team. You can still edit every section.
                </p>
            </div>

            @foreach ($steps as $i => $step)
                <div class="rounded-2xl bg-white/5 ring-1 ring-white/10 overflow-hidden">
                    <div class="flex items-center justify-between gap-3 px-5 py-3.5 border-b border-white/10">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-300">{{ $step['label'] }}</p>
                        <button type="button" @click="goTo({{ $i }}); reviewing = false"
                                class="rounded-lg bg-white/10 px-3 py-1.5 text-xs font-semibold uppercase tracking-widest text-white ring-1 ring-white/15 hover:bg-white/15">
                            Edit
                        </button>
                    </div>
                    <dl class="px-5 pb-4">
                        @foreach ($step['questions'] as $key => $q)
                            @continue (! BrandBrief::isVisible($key, $answers))
                            @php
                                $shown = $brief->exists ? $brief->displayAnswer($key) : '';
                            @endphp
                            <div class="py-3 border-b border-white/5 last:border-0">
                                <dt class="text-xs text-brand-100/50 mb-1">{{ $q['label'] }}</dt>
                                <dd class="text-sm leading-snug whitespace-pre-wrap {{ $shown !== '' ? 'text-white' : 'text-brand-100/30' }}">
                                    {{ $shown !== '' ? $shown : (($q['required'] ?? false) ? 'Still needed' : 'Not answered') }}
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endforeach

            @if ($showName)
                {{-- Asked last: a name field at the top is the one most likely
                     to make somebody close the tab, and by here they have
                     already decided to send it. --}}
                <div class="rounded-2xl bg-white/5 ring-1 ring-white/10 p-5">
                    <label for="submitted_name" class="block text-base font-semibold text-white">Who is filling this in?</label>
                    <p class="mt-1 text-xs text-brand-100/60">So we know who to come back to with questions.</p>
                    <input type="text" id="submitted_name" name="submitted_name" value="{{ old('submitted_name') }}"
                           placeholder="Your name" autocomplete="name"
                           class="mt-2 block w-full max-w-sm rounded-lg border-0 bg-white/10 px-3.5 py-2.5 text-sm text-white placeholder-brand-100/40 ring-1 ring-inset ring-white/15 focus:ring-2 focus:ring-inset focus:ring-brand-400">
                </div>
            @endif
        </div>

        {{-- Controls. Back / Save & exit / Continue, and on the review screen
             the one irreversible button on the page. --}}
        <div class="flex flex-wrap items-center justify-between gap-2.5 mt-4">
            <button type="button" @click="back()" x-show="step > 0 || reviewing"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-white/10 px-4 py-2.5 text-xs font-semibold uppercase tracking-widest text-white ring-1 ring-white/15 hover:bg-white/15 transition-colors">
                <x-icon name="chevron-left" class="w-4 h-4" /> Back
            </button>
            <div x-show="step === 0 && ! reviewing"></div>

            <div class="flex flex-wrap items-center gap-2.5">
                @if ($exitUrl)
                    <button type="button" @click="saveNow().then(() => window.location = @js($exitUrl))"
                            class="rounded-lg px-4 py-2.5 text-xs font-semibold uppercase tracking-widest text-brand-100/70 hover:text-white transition-colors">
                        Save &amp; exit
                    </button>
                @endif

                <button type="button" x-show="! reviewing" @click="next()"
                        class="rounded-lg bg-brand-400 px-5 py-2.5 text-xs font-semibold uppercase tracking-widest text-brand-900 hover:bg-brand-300 transition-colors">
                    <span x-text="step === {{ count($steps) - 1 }} ? 'Save & review' : 'Save & continue'"></span>
                </button>

                <button type="submit" x-show="reviewing" x-cloak @click="confirmSubmit"
                        class="rounded-lg bg-brand-400 px-5 py-2.5 text-xs font-semibold uppercase tracking-widest text-brand-900 hover:bg-brand-300 transition-colors">
                    {{ $submitLabel }}
                </button>
            </div>
        </div>

        {{-- A failed save gets its own loud, differently-coloured line rather
             than folding into the calm status text below -- that text used
             to say "Answers save as you type" regardless of whether saving
             had ever actually succeeded, which is exactly how a real
             client's brief went unsaved for a full session without them
             noticing anything was wrong. --}}
        <p class="mt-3.5 text-center text-xs" x-show="saveFailed" x-cloak>
            <span class="text-red-300 font-semibold">Your answers aren't saving right now.</span>
            <span class="text-brand-100/50">Please don't close this tab -- try reloading the page in a new tab to check your connection, or contact us if this continues.</span>
        </p>
        <p class="mt-3.5 text-center text-xs text-brand-100/40" x-show="! saveFailed">
            <span x-show="saving">Saving…</span>
            <span x-show="! saving && savedAt" x-cloak>Saved automatically. You can close this and pick up where you left off.</span>
            <span x-show="! saving && ! savedAt">Answers save as you type. You can close this and pick up where you left off.</span>
        </p>
    </form>
</div>

@once
    @push('scripts')
        <script>
            /*
             * The wizard's behaviour, kept out of the markup because three
             * components share it. Autosave is debounced and posts the whole
             * form -- the payload is small and the alternative is tracking
             * which field changed, which is more code and one more thing to
             * get wrong.
             */
            document.addEventListener('alpine:init', () => {
                Alpine.data('briefWizard', (config) => ({
                    step: config.startAt || 0,
                    furthest: config.startAt || 0,
                    done: [],
                    reviewing: false,
                    saving: false,
                    savedAt: null,
                    dirty: false,
                    answered: config.answered,
                    total: config.total,
                    answers: {},
                    timer: null,
                    saveFailed: false,

                    init() {
                        this.readAnswers();
                        this.markDone();

                        // Any change anywhere in the form schedules a save and
                        // refreshes the live showIf answers.
                        this.$root.addEventListener('input', () => this.touched());
                        this.$root.addEventListener('change', () => this.touched());

                        window.addEventListener('beforeunload', (e) => {
                            if (this.dirty) { e.preventDefault(); e.returnValue = ''; }
                        });
                    },

                    get pct() {
                        const reached = this.reviewing ? config.steps : this.step + 1;
                        return Math.round((reached / config.steps) * 100);
                    },

                    get stepTitle() {
                        return config.titles[this.step] || '';
                    },

                    /* Mirror the form into a plain object so showIf conditions
                       can be evaluated without a round trip. */
                    readAnswers() {
                        const data = new FormData(this.$refs.form);
                        const next = {};
                        for (const [k, v] of data.entries()) {
                            const m = k.match(/^answers\[([^\]]+)\](\[\])?$/);
                            if (!m) continue;
                            if (m[2]) { (next[m[1]] ||= []).push(v); } else { next[m[1]] = v; }
                        }
                        this.answers = next;
                    },

                    touched() {
                        this.dirty = true;
                        this.readAnswers();
                        clearTimeout(this.timer);
                        this.timer = setTimeout(() => this.saveNow(), 900);
                    },

                    async saveNow() {
                        clearTimeout(this.timer);
                        this.saving = true;
                        try {
                            const res = await fetch(config.saveUrl, {
                                method: 'POST',
                                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                body: new FormData(this.$refs.form),
                            });
                            /*
                             * fetch() only rejects on a NETWORK failure (DNS,
                             * connection refused) -- an HTTP error response
                             * (419, 422, 500, a throttle 429) resolves
                             * normally with res.ok === false and would
                             * previously fall straight through this block
                             * silently, with nothing telling the client their
                             * answers were not actually being saved. This is
                             * the exact bug that lost a real client's brief
                             * (Thillai Pets Clinic): the session expired
                             * partway through a long form, every autosave
                             * after that quietly 419'd, and the one visible
                             * signal ("Saved automatically" vs "Answers save
                             * as you type") never distinguished "saving" from
                             * "has never once succeeded" clearly enough to
                             * notice.
                             */
                            if (res.ok) {
                                const json = await res.json();
                                this.answered = json.answered;
                                this.total = json.total;
                                this.savedAt = json.saved_at;
                                this.dirty = false;
                                this.saveFailed = false;
                            } else {
                                this.saveFailed = true;
                            }
                        } catch (e) {
                            // A genuine network failure -- same visible
                            // treatment as an HTTP error response. The
                            // answers are still in the form and beforeunload
                            // still guards the tab either way, but the client
                            // needs to know saving is NOT currently working
                            // rather than assume it is.
                            this.saveFailed = true;
                        }
                        this.saving = false;
                    },

                    markDone() {
                        this.done = [];
                        for (let i = 0; i < this.step; i++) this.done.push(i);
                    },

                    goTo(i) {
                        if (i > this.furthest) return;
                        this.reviewing = false;
                        this.step = i;
                        this.markDone();
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                    },

                    next() {
                        this.saveNow();
                        if (this.step === config.steps - 1) {
                            this.reviewing = true;
                        } else {
                            this.step += 1;
                            this.furthest = Math.max(this.furthest, this.step);
                        }
                        this.markDone();
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                    },

                    back() {
                        if (this.reviewing) { this.reviewing = false; return; }
                        this.step = Math.max(0, this.step - 1);
                        this.markDone();
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                    },

                    confirmSubmit(e) {
                        if (!window.confirm(@js($confirm))) e.preventDefault();
                    },
                }));

                Alpine.data('chipGroup', (config) => ({
                    multi: config.multi,
                    name: config.name,
                    selected: config.selected || [],

                    has(option) { return this.selected.includes(option); },

                    toggle(option) {
                        if (!this.multi) {
                            this.selected = this.has(option) ? [] : [option];
                            return;
                        }
                        // "None" is exclusive: it is the answer that says there
                        // is nothing to list, so it cannot sit beside items.
                        if (option === 'None') {
                            this.selected = this.has('None') ? [] : ['None'];
                            return;
                        }
                        this.selected = this.has(option)
                            ? this.selected.filter((x) => x !== option)
                            : [...this.selected.filter((x) => x !== 'None'), option];
                    },
                }));

                Alpine.data('urlList', (config) => ({
                    rows: config.rows,
                    max: config.max,
                    valid(v) { return /^https?:\/\/[^\s.]+\.[^\s]{2,}$/i.test((v || '').trim()); },
                }));
            });
        </script>
    @endpush
@endonce
