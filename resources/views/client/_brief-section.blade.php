@php
    use App\Support\BrandBrief;

    /*
     * One section of the brand brief. Rendered in a loop from brief.blade.php,
     * so adding a question stays one entry in App\Support\BrandBrief and does
     * not mean touching Blade.
     *
     * Fields are styled with the class strings passed down rather than
     * x-text-input and friends. Those components are light-surfaced and have
     * around seventy staff callers; the client area is brand-900, and giving a
     * shared component a dark mode for one page is a change with seventy
     * places to regress. One page owning its own field styling is the smaller
     * risk. x-input-error is used as-is -- red reads on dark.
     */
@endphp

@php
    $progress = $brief->exists ? $brief->sectionProgress($key) : ['answered' => 0, 'total' => count($questions)];
@endphp

<section x-data="{ open: {{ $loop->first ? 'true' : 'false' }} }" x-init="if (window.innerWidth >= 1024) open = true"
         class="rounded-2xl bg-white/5 ring-1 ring-white/10 overflow-hidden">

    <button type="button" @click="open = !open"
            class="w-full flex items-start justify-between gap-4 p-5 sm:p-6 text-left hover:bg-white/[0.03] transition-colors">
        <div class="min-w-0">
            <h2 class="text-lg sm:text-xl font-extrabold tracking-tight">{{ $section['label'] }}</h2>
            <p class="mt-1 text-sm text-brand-100/60 leading-snug">{{ $section['blurb'] }}</p>
        </div>
        <div class="flex shrink-0 items-center gap-3">
            <span class="text-[11px] font-semibold uppercase tracking-widest tabular-nums text-brand-100/50">
                {{ $progress['answered'] }}/{{ $progress['total'] }}
            </span>
            <x-icon name="chevron-right" class="w-5 h-5 text-brand-100/50 transition-transform"
                    ::class="open && 'rotate-90'" />
        </div>
    </button>

    {{-- Plain x-show. x-collapse would read better but belongs to
         @alpinejs/collapse, which this app does not load, and a directive
         Alpine has never heard of silently does nothing. --}}
    <div x-show="open" class="px-5 sm:px-6 pb-6 space-y-6 border-t border-white/10 pt-6">
        @foreach ($questions as $qKey => $question)
            @php
                $name = "answers[{$qKey}]";
                $id = "q-{$qKey}";
                $current = old("answers.{$qKey}", $brief->exists ? $brief->answer($qKey) : null);
                $errorKey = "answers.{$qKey}";
            @endphp

            <div>
                <label for="{{ $id }}" class="{{ $label }}">
                    {{ $question['label'] }}
                    @if ($question['required'])
                        <span class="text-amber-300/90" title="Needed before you can submit">*</span>
                    @endif
                </label>

                @if (! empty($question['hint']))
                    <p class="{{ $hint }}">{{ $question['hint'] }}</p>
                @endif

                @switch ($question['type'])
                    @case (BrandBrief::TYPE_TEXTAREA)
                        <textarea id="{{ $id }}" name="{{ $name }}" rows="3"
                                  maxlength="{{ $question['max'] ?? BrandBrief::DEFAULT_MAX }}"
                                  class="{{ $field }}">{{ $current }}</textarea>
                        @break

                    @case (BrandBrief::TYPE_SELECT)
                        <select id="{{ $id }}" name="{{ $name }}" class="{{ $field }}">
                            <option value="">Choose one</option>
                            @foreach ($options[$qKey] ?? [] as $term)
                                <option value="{{ $term->id }}" @selected((string) $current === (string) $term->id)>{{ $term->name }}</option>
                            @endforeach
                        </select>
                        @break

                    @case (BrandBrief::TYPE_CHOICE)
                        {{-- Radios, not a select. Four short options are faster to
                             read laid out than hidden behind a tap. --}}
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            @foreach (BrandBrief::optionsFor($qKey) as $optKey => $optLabel)
                                <label class="flex items-center gap-2.5 rounded-lg bg-white/5 ring-1 ring-white/10 px-3.5 py-2.5 text-sm cursor-pointer hover:bg-white/10 transition-colors">
                                    <input type="radio" name="{{ $name }}" value="{{ $optKey }}"
                                           @checked((string) $current === (string) $optKey)
                                           class="border-white/30 bg-white/10 text-brand-400 focus:ring-brand-400 focus:ring-offset-0">
                                    <span>{{ $optLabel }}</span>
                                </label>
                            @endforeach
                        </div>
                        @break

                    @case (BrandBrief::TYPE_MULTISELECT)
                        @php
                            $selected = collect(is_array($current) ? $current : [])->map(fn ($v) => (string) $v);
                            $limit = $question['limit'] ?? 10;
                            $list = BrandBrief::taxonomyFor($qKey)
                                ? ($options[$qKey] ?? collect())->mapWithKeys(fn ($t) => [$t->id => $t->name])->all()
                                : BrandBrief::optionsFor($qKey);
                        @endphp

                        {{-- Checkboxes with a client-side cap. The cap is also a
                             validation rule -- this only saves the client from
                             finding out after a round trip. --}}
                        <div class="mt-2 flex flex-wrap gap-2"
                             x-data="{ limit: {{ $limit }}, count: {{ $selected->count() }} }">
                            @foreach ($list as $optKey => $optLabel)
                                <label class="inline-flex items-center gap-2 rounded-full bg-white/5 ring-1 ring-white/10 px-3.5 py-2 text-sm cursor-pointer hover:bg-white/10 transition-colors has-[:checked]:bg-brand-400/20 has-[:checked]:ring-brand-400/50">
                                    <input type="checkbox" name="{{ $name }}[]" value="{{ $optKey }}"
                                           @checked($selected->contains((string) $optKey))
                                           @change="count += $event.target.checked ? 1 : -1;
                                                    if (count > limit) { $event.target.checked = false; count--; }"
                                           class="rounded border-white/30 bg-white/10 text-brand-400 focus:ring-brand-400 focus:ring-offset-0">
                                    <span>{{ $optLabel }}</span>
                                </label>
                            @endforeach
                        </div>
                        @break

                    @case (BrandBrief::TYPE_URL)
                        <input id="{{ $id }}" name="{{ $name }}" type="url" inputmode="url"
                               placeholder="https://" value="{{ $current }}" class="{{ $field }}">
                        @break

                    @case (BrandBrief::TYPE_NUMBER)
                        <input id="{{ $id }}" name="{{ $name }}" type="number" inputmode="numeric"
                               value="{{ $current }}" class="{{ $field }}">
                        @break

                    @default
                        <input id="{{ $id }}" name="{{ $name }}" type="text"
                               maxlength="{{ $question['max'] ?? 255 }}"
                               value="{{ $current }}" class="{{ $field }}">
                @endswitch

                @error($errorKey)
                    <p class="mt-1.5 text-sm text-red-300">{{ $message }}</p>
                @enderror
                @error($errorKey.'.*')
                    <p class="mt-1.5 text-sm text-red-300">{{ $message }}</p>
                @enderror
            </div>
        @endforeach
    </div>
</section>
