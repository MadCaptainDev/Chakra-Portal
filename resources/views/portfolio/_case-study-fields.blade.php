@php
    /*
    | The optional case-study half of a portfolio piece: what it was, how it
    | performed, and what it did for the client. Everything here is optional --
    | the public detail screen drops any section it has no numbers for.
    |
    | Expects: $item.
    |
    | Kept collapsed by default so the everyday job (title, video, thumbnail)
    | is not buried under forty number boxes.
    */

    // Five rows is what the public strip shows; blank ones are dropped on save.
    $beforeAfter = old('before_after', $item->before_after ?? []);
    $beforeAfter = array_pad(array_values((array) $beforeAfter), 5, ['label' => '', 'before' => '', 'after' => '']);

    $numbers = [
        'Reach and engagement' => [
            'views' => 'Views',
            'reach' => 'Reach',
            'likes' => 'Likes',
            'comments' => 'Comments',
            'shares' => 'Shares',
            'saves' => 'Saves',
            'profile_visits' => 'Profile visits',
            'enquiries' => 'Enquiries',
        ],
        'Business impact' => [
            'leads' => 'Leads generated',
            'whatsapp_enquiries' => 'WhatsApp enquiries',
            'calls' => 'Calls',
            'store_visits' => 'Store visits',
            'orders' => 'Orders',
        ],
        "The client's average piece" => [
            'benchmark_views' => 'Average views',
            'benchmark_reach' => 'Average reach',
            'benchmark_engagements' => 'Average engagements',
            'benchmark_enquiries' => 'Average enquiries',
        ],
    ];
@endphp

<div class="rounded-lg border border-gray-200" x-data="{ open: @js((bool) $item->hasCaseStudy()) }">
    <button type="button" @click="open = ! open"
            class="flex w-full items-center justify-between gap-3 p-4 min-h-[44px] text-left">
        <span>
            <span class="block font-semibold text-gray-900 text-sm">Case study (optional)</span>
            <span class="block text-xs text-gray-500 mt-0.5">
                Numbers and creative notes. Fill these in and the piece gets its own page on the website.
            </span>
        </span>
        <svg class="w-5 h-5 shrink-0 text-gray-400" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24"
             stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
        </svg>
    </button>

    <div x-show="open" x-cloak class="border-t border-gray-200 p-4 space-y-6">
        {{-- What it was --}}
        <div class="space-y-4">
            <div>
                <x-input-label for="summary" value="Summary" />
                <x-textarea id="summary" name="summary" rows="3" class="mt-1"
                            placeholder="The paragraph under the title on the case-study page.">{{ old('summary', $item->summary) }}</x-textarea>
                <x-input-error :messages="$errors->get('summary')" class="mt-2" />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="platform_id" value="Platform" />
                    <x-select id="platform_id" name="platform_id" class="mt-1">
                        <option value="">Not set</option>
                        @foreach ($lists['platform'] as $term)
                            <option value="{{ $term->id }}" @selected((int) old('platform_id', $item->platform_id) === $term->id)>
                                {{ $term->name }}@unless ($term->is_active) (retired)@endunless
                            </option>
                        @endforeach
                    </x-select>
                    @if ($item->platform_id === null && filled($item->platform))
                        <p class="mt-1 text-xs text-amber-700">Currently showing “{{ $item->platform }}”, typed before this became a list.</p>
                    @endif
                    <x-input-error :messages="$errors->get('platform_id')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="format_id" value="Format" />
                    <x-select id="format_id" name="format_id" class="mt-1">
                        <option value="">Not set</option>
                        @foreach ($lists['format'] as $term)
                            <option value="{{ $term->id }}" @selected((int) old('format_id', $item->format_id) === $term->id)>
                                {{ $term->name }}@unless ($term->is_active) (retired)@endunless
                            </option>
                        @endforeach
                    </x-select>
                    <p class="mt-1 text-xs text-gray-500">A format containing “9:16” shows the cover vertically.</p>
                    @if ($item->format_id === null && filled($item->format))
                        <p class="mt-1 text-xs text-amber-700">Currently showing “{{ $item->format }}”, typed before this became a list.</p>
                    @endif
                    <x-input-error :messages="$errors->get('format_id')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="duration_seconds" value="Duration (seconds)" />
                    <x-text-input id="duration_seconds" name="duration_seconds" type="number" min="0" max="86400" class="mt-1"
                                  value="{{ old('duration_seconds', $item->duration_seconds) }}" placeholder="32" />
                    <x-input-error :messages="$errors->get('duration_seconds')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="published_on" value="Published on" />
                    <x-text-input id="published_on" name="published_on" type="date" class="mt-1"
                                  value="{{ old('published_on', $item->published_on?->format('Y-m-d')) }}" />
                    <x-input-error :messages="$errors->get('published_on')" class="mt-2" />
                </div>
                <div class="sm:col-span-2">
                    <x-input-label for="objective_id" value="Objective" />
                    <x-select id="objective_id" name="objective_id" class="mt-1">
                        <option value="">Not set</option>
                        @foreach ($lists['objective'] as $term)
                            <option value="{{ $term->id }}" @selected((int) old('objective_id', $item->objective_id) === $term->id)>
                                {{ $term->name }}@unless ($term->is_active) (retired)@endunless
                            </option>
                        @endforeach
                    </x-select>
                    @if ($item->objective_id === null && filled($item->objective))
                        <p class="mt-1 text-xs text-amber-700">Currently showing “{{ $item->objective }}”, typed before this became a list.</p>
                    @endif
                    <p class="mt-1 text-xs text-gray-500">
                        Platform, format and objective come from the
                        <a href="{{ route('taxonomy.index') }}" target="_blank" rel="noopener"
                           class="text-brand-500 hover:text-brand-600 font-semibold">master lists</a>.
                    </p>
                    <x-input-error :messages="$errors->get('objective_id')" class="mt-2" />
                </div>
            </div>
        </div>

        {{-- Whether the money side is ours to print. --}}
        <label class="flex items-start gap-3 rounded-lg border border-gray-200 p-3 min-h-[44px] cursor-pointer hover:bg-gray-50">
            <input type="checkbox" name="show_business_impact" value="1"
                   @checked(old('show_business_impact', $item->exists ? $item->show_business_impact : true))
                   class="mt-0.5 rounded border-gray-300 text-brand-500 focus:ring-brand-400">
            <span>
                <span class="block text-sm font-semibold text-gray-900">Show sales and orders on the website</span>
                <span class="block text-xs text-gray-500">
                    Untick when the client's revenue is not ours to publish. The figures stay on file here;
                    only reach and engagement go on the public page.
                </span>
            </span>
        </label>

        {{-- The numbers --}}
        @foreach ($numbers as $heading => $fields)
            <div>
                <h5 class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $heading }}</h5>
                <div class="mt-3 grid grid-cols-2 sm:grid-cols-4 gap-3">
                    @foreach ($fields as $field => $label)
                        <div>
                            <x-input-label :for="$field" :value="$label" />
                            <x-text-input :id="$field" :name="$field" type="number" min="0" class="mt-1"
                                          value="{{ old($field, $item->{$field}) }}" />
                            <x-input-error :messages="$errors->get($field)" class="mt-2" />
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        {{-- Rates and money --}}
        <div>
            <h5 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Rates, watch time and sales</h5>
            <div class="mt-3 grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div>
                    <x-input-label for="engagement_rate" value="Engagement rate %" />
                    <x-text-input id="engagement_rate" name="engagement_rate" type="number" step="0.01" min="0" max="100" class="mt-1"
                                  value="{{ old('engagement_rate', $item->engagement_rate) }}" />
                    <x-input-error :messages="$errors->get('engagement_rate')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="completion_rate" value="Completion rate %" />
                    <x-text-input id="completion_rate" name="completion_rate" type="number" step="0.01" min="0" max="100" class="mt-1"
                                  value="{{ old('completion_rate', $item->completion_rate) }}" />
                    <x-input-error :messages="$errors->get('completion_rate')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="watch_hours" value="Total watch hours" />
                    <x-text-input id="watch_hours" name="watch_hours" type="number" step="0.1" min="0" class="mt-1"
                                  value="{{ old('watch_hours', $item->watch_hours) }}" />
                    <x-input-error :messages="$errors->get('watch_hours')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="avg_watch_seconds" value="Avg. watch (seconds)" />
                    <x-text-input id="avg_watch_seconds" name="avg_watch_seconds" type="number" step="0.1" min="0" class="mt-1"
                                  value="{{ old('avg_watch_seconds', $item->avg_watch_seconds) }}" />
                    <x-input-error :messages="$errors->get('avg_watch_seconds')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="sales_before_amount" value="Monthly sales before" />
                    <x-text-input id="sales_before_amount" name="sales_before_amount" type="number" step="0.01" min="0" class="mt-1"
                                  value="{{ old('sales_before_amount', $item->sales_before_amount) }}" />
                    <x-input-error :messages="$errors->get('sales_before_amount')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="sales_amount" value="Attributed sales" />
                    <x-text-input id="sales_amount" name="sales_amount" type="number" step="0.01" min="0" class="mt-1"
                                  value="{{ old('sales_amount', $item->sales_amount) }}" />
                    <x-input-error :messages="$errors->get('sales_amount')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="benchmark_sales_amount" value="Average piece's sales" />
                    <x-text-input id="benchmark_sales_amount" name="benchmark_sales_amount" type="number" step="0.01" min="0" class="mt-1"
                                  value="{{ old('benchmark_sales_amount', $item->benchmark_sales_amount) }}" />
                    <x-input-error :messages="$errors->get('benchmark_sales_amount')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="roi" value="Return on spend" />
                    <x-text-input id="roi" name="roi" type="number" step="0.1" min="0" class="mt-1"
                                  value="{{ old('roi', $item->roi) }}" placeholder="6.4" />
                    <x-input-error :messages="$errors->get('roi')" class="mt-2" />
                </div>
            </div>
            <p class="mt-2 text-xs text-gray-500">Amounts in rupees, plain numbers &mdash; the page shortens them to L and Cr.</p>
        </div>

        {{-- Creative notes --}}
        <div>
            <h5 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Creative strategy</h5>
            <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
                @foreach (\App\Models\PortfolioItem::CREATIVE_FIELDS as $field => $label)
                    <div>
                        <x-input-label :for="$field" :value="$label" />
                        <x-textarea :id="$field" :name="$field" rows="2" class="mt-1">{{ old($field, $item->{$field}) }}</x-textarea>
                        <x-input-error :messages="$errors->get($field)" class="mt-2" />
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Before / after --}}
        <div>
            <h5 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Before vs after</h5>
            <p class="mt-1 text-xs text-gray-500">
                Free text, so write it the way the client reads it &mdash; &ldquo;240K&rdquo; to &ldquo;2.1M&rdquo;. Blank rows are ignored.
            </p>
            <div class="mt-3 space-y-3">
                @foreach ($beforeAfter as $index => $row)
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <x-text-input name="before_after[{{ $index }}][label]" type="text"
                                      value="{{ $row['label'] ?? '' }}" placeholder="Metric, e.g. Monthly reach"
                                      aria-label="Before/after metric {{ $index + 1 }}" />
                        <x-text-input name="before_after[{{ $index }}][before]" type="text"
                                      value="{{ $row['before'] ?? '' }}" placeholder="Before"
                                      aria-label="Before value {{ $index + 1 }}" />
                        <x-text-input name="before_after[{{ $index }}][after]" type="text"
                                      value="{{ $row['after'] ?? '' }}" placeholder="After"
                                      aria-label="After value {{ $index + 1 }}" />
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
