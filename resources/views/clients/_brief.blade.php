@php
    use App\Support\BrandBrief;

    /*
     * The brand brief, read-only, as the studio sees it.
     *
     * One partial for two callers: the full record on clients/show, and the
     * condensed drawer a writer gets on the script editor. The difference is
     * the $keys shortlist -- a second implementation for the drawer would
     * drift from this one within a month.
     *
     * $brief   ClientBrief|null
     * $keys    list<string>|null   a shortlist, or null for everything
     * $compact bool                drops the section headings for the drawer
     */
    $keys = $keys ?? null;
    $compact = $compact ?? false;
    $answers = $brief?->keyedAnswers() ?? collect();

    // Iterate the catalogue, not the rows: a question removed from
    // BrandBrief leaves its answers behind on purpose, and they must not
    // resurface here as an unlabelled blob.
    $show = $keys
        ? collect($keys)->mapWithKeys(fn ($k) => [$k => BrandBrief::QUESTIONS[$k] ?? null])->filter()
        : collect(BrandBrief::QUESTIONS);
@endphp

@if (! $brief || ! $brief->exists)
    <p class="text-sm text-gray-500">
        Nothing yet — this client has not started their brand brief.
    </p>
@else
    <div class="{{ $compact ? 'space-y-3' : 'space-y-6' }}">
        @foreach (BrandBrief::sections() as $sectionKey => $section)
            @php
                $questions = $show->filter(fn ($q) => $q['section'] === $sectionKey);
            @endphp

            @continue ($questions->isEmpty())

            <div>
                @unless ($compact)
                    <h4 class="text-[11px] font-semibold uppercase tracking-wider text-brand-600 mb-2">
                        {{ $section['label'] }}
                    </h4>
                @endunless

                <dl class="{{ $compact ? 'space-y-2.5' : 'divide-y divide-gray-100' }}">
                    @foreach ($questions as $key => $question)
                        @php
                            $answer = $answers->get($key);
                            $answered = $answer?->isAnswered() ?? false;
                        @endphp

                        {{-- The drawer shows only what was answered. The full
                             record shows the gaps too, because "we never asked"
                             and "they skipped it" look identical otherwise. --}}
                        @continue ($compact && ! $answered)

                        <div class="{{ $compact ? '' : 'py-2.5' }}">
                            <dt class="text-xs text-gray-500 leading-snug">
                                {{ $question['label'] }}
                                @if ($brief->editedSinceSubmit($key))
                                    <span class="ml-1 text-[10px] font-semibold uppercase tracking-wider text-amber-600"
                                          title="Changed after the brief was sent in">
                                        edited {{ $answer->updated_at->diffForHumans(null, true) }} ago
                                    </span>
                                @endif
                            </dt>
                            <dd class="mt-0.5 text-sm {{ $answered ? 'text-gray-900 whitespace-pre-line' : 'text-gray-400' }}">
                                {{-- Plain {{ }}. These are answers a client typed,
                                     rendered as text and never as markup. --}}
                                {{ $answered ? $answer->display() : '—' }}
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @endforeach
    </div>
@endif
