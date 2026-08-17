@php
    use App\Support\BrandBrief;

    /*
     * The brand brief, read-only, as the studio sees it.
     *
     * One partial for two callers, and the difference is $compact:
     *
     * - The full record on clients/show, where the seven groups are TABS. One
     *   scrolling list of twenty questions is a list nobody reads to the end
     *   of; a tab per group is the same shape the client filled it in, so
     *   "what did they say about audience" is one tap rather than a hunt.
     * - The condensed drawer on the script editor, which is a shortlist and
     *   stays a plain list -- tabs inside a drawer would be a second
     *   navigation inside a panel that is already a detour.
     *
     * $brief   ClientBrief|null
     * $keys    list<string>|null   a shortlist, or null for everything
     * $compact bool                drops the tabs and the gaps, for the drawer
     */
    $keys = $keys ?? null;
    $compact = $compact ?? false;
    $answers = $brief?->keyedAnswers() ?? collect();
    $given = $brief?->exists ? $brief->answerMap() : [];

    // Iterate the catalogue, not the rows: a question removed from BrandBrief
    // leaves its answers behind on purpose, and they must not resurface here
    // as an unlabelled blob.
    $show = $keys
        ? collect($keys)->mapWithKeys(fn ($k) => [$k => BrandBrief::question($k)])->filter()
        : collect(BrandBrief::questions());

    // Groups that actually have something to show, with their answered count.
    $groups = collect(BrandBrief::sections())
        ->map(function (array $section, string $sectionKey) use ($show, $answers, $given, $brief) {
            $questions = $show
                ->filter(fn ($q) => $q['section'] === $sectionKey)
                // A conditional question the client was never asked is not a
                // gap in their brief, so it is not shown as one.
                ->filter(fn ($q, $key) => BrandBrief::isVisible($key, $given));

            return [
                'label' => $section['label'],
                'questions' => $questions,
                'answered' => $questions->keys()
                    ->filter(fn (string $key) => $answers->get($key)?->isAnswered() ?? false)
                    ->count(),
                'total' => $questions->count(),
            ];
        })
        ->reject(fn (array $group) => $group['questions']->isEmpty());
@endphp

@if (! $brief || ! $brief->exists)
    <p class="text-sm text-gray-500">
        Nothing yet — this client has not started their brand brief.
    </p>
@elseif ($compact)
    {{-- The drawer: answered questions only, no headings, no tabs. --}}
    <div class="space-y-3">
        @foreach ($groups as $group)
            @foreach ($group['questions'] as $key => $question)
                @php $answer = $answers->get($key); @endphp
                @continue (! ($answer?->isAnswered() ?? false))

                <div>
                    <dt class="text-xs text-gray-500 leading-snug">
                        {{ $question['label'] }}
                        @if ($brief->editedSinceSubmit($key))
                            <span class="ml-1 text-[10px] font-semibold uppercase tracking-wider text-amber-600"
                                  title="Changed after the brief was sent in">
                                edited {{ $answer->updated_at->diffForHumans(null, true) }} ago
                            </span>
                        @endif
                    </dt>
                    <dd class="mt-0.5 text-sm text-gray-900 whitespace-pre-line">
                        {{ $brief->displayAnswer($key) }}
                    </dd>
                </div>
            @endforeach
        @endforeach
    </div>
@else
    {{-- The full record, one tab per group. Every panel is rendered and Alpine
         only switches which is visible, so moving between groups costs nothing
         and the browser's find-in-page still reaches all of it. --}}
    <div x-data="{ group: '{{ $groups->keys()->first() }}' }">

        <div class="overflow-x-auto -mx-1 px-1 pb-1 mb-4">
            <x-tab-nav model="group" :tabs="$groups->map(fn ($g) => [
                'label' => $g['label'],
                'count' => $g['answered'].'/'.$g['total'],
            ])->all()" />
        </div>

        @foreach ($groups as $groupKey => $group)
            <div x-show="group === '{{ $groupKey }}'" x-cloak>
                <dl class="divide-y divide-gray-100">
                    @foreach ($group['questions'] as $key => $question)
                        @php
                            $answer = $answers->get($key);
                            $answered = $answer?->isAnswered() ?? false;
                        @endphp

                        <div class="py-3">
                            <dt class="text-xs text-gray-500 leading-snug">
                                {{ $question['label'] }}
                                @if ($answered && $brief->editedSinceSubmit($key))
                                    <span class="ml-1 text-[10px] font-semibold uppercase tracking-wider text-amber-600"
                                          title="Changed after the brief was sent in">
                                        edited {{ $answer->updated_at->diffForHumans(null, true) }} ago
                                    </span>
                                @endif
                            </dt>
                            <dd class="mt-1 text-sm {{ $answered ? 'text-gray-900 whitespace-pre-line' : 'text-gray-400' }}">
                                {{-- Plain {{ }}. These are answers a client
                                     typed, rendered as text and never markup. --}}
                                {{ $answered ? $brief->displayAnswer($key) : '—' }}
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @endforeach
    </div>
@endif
