@php
    use App\Support\BrandBrief;

    /*
    | One question, whatever its type.
    |
    | A port of the design's Question/Ask components, in the portal's dark
    | palette rather than the kit's light one. Every control writes into
    | answers[key], so the whole wizard is one form and Alpine only decides
    | which step is on screen.
    |
    | $key       question key
    | $q         the question from BrandBrief
    | $brief     ClientBrief (may not exist yet)
    */
    $value = $brief->exists ? $brief->answer($key) : null;
    $value = old('answers.'.$key, $value);

    $otherKey = $key.'_other';
    $otherValue = old('answers.'.$otherKey, $brief->exists ? $brief->answer($otherKey) : null);

    $error = $errors->first('answers.'.$key) ?: $errors->first('answers.'.$key.'.*');

    $labelCls = 'block text-base font-semibold text-white leading-snug';
    $helpCls = 'mt-1 text-xs text-brand-100/60 leading-snug';
    $fieldCls = 'block w-full rounded-lg border-0 bg-white/10 px-3.5 py-2.5 text-sm text-white placeholder-brand-100/40 ring-1 ring-inset ring-white/15 focus:ring-2 focus:ring-inset focus:ring-brand-400';
@endphp

<div class="grid gap-2" data-question="{{ $key }}">
    <div>
        <label class="{{ $labelCls }}">
            {{ $q['label'] }}
            @if ($q['required'] ?? false)
                <span class="text-red-400 ml-0.5">*</span>
            @elseif ($q['optional'] ?? false)
                <span class="ml-1.5 text-xs font-normal text-brand-100/40">Optional</span>
            @endif
        </label>
        @if ($q['help'] ?? null)
            <p class="{{ $helpCls }}">{{ $q['help'] }}</p>
        @endif
    </div>

    @switch ($q['type'])

        @case (BrandBrief::TYPE_TEXTAREA)
            <textarea name="answers[{{ $key }}]" rows="{{ $q['rows'] ?? 4 }}"
                      placeholder="{{ $q['placeholder'] ?? '' }}"
                      class="{{ $fieldCls }}">{{ $value }}</textarea>
            @break

        @case (BrandBrief::TYPE_TEXT)
            <input type="text" name="answers[{{ $key }}]" value="{{ $value }}"
                   placeholder="{{ $q['placeholder'] ?? '' }}" class="{{ $fieldCls }}">
            @break

        @case (BrandBrief::TYPE_CHIPS)
        @case (BrandBrief::TYPE_CHECKS)
            @php
                $multi = $q['multi'] ?? false;
                $selected = $multi ? (array) ($value ?? []) : array_filter([$value]);
                $isCheck = $q['type'] === BrandBrief::TYPE_CHECKS;
            @endphp

            {{-- The chip carries its own state in Alpine so selecting one is
                 instant, and mirrors into a hidden input so the form posts
                 normally. No JSON endpoint, no re-render. --}}
            <div x-data="chipGroup({
                    multi: {{ $multi ? 'true' : 'false' }},
                    selected: @js(array_values($selected)),
                    name: 'answers[{{ $key }}]'
                 })"
                 class="grid gap-2.5">

                <div class="flex flex-wrap gap-2" role="{{ $multi ? 'group' : 'radiogroup' }}">
                    @foreach ($q['options'] as $option)
                        <button type="button" @click="toggle(@js($option))"
                                :aria-checked="has(@js($option)) ? 'true' : 'false'"
                                role="{{ $multi ? 'checkbox' : 'radio' }}"
                                class="inline-flex items-center gap-2 min-h-[44px] px-4 text-sm font-semibold text-left transition-colors
                                       {{ $isCheck ? 'rounded-md pl-3 pr-3.5' : 'rounded-full' }}"
                                :class="has(@js($option))
                                    ? 'bg-brand-400/20 text-white ring-[1.5px] ring-inset ring-brand-400'
                                    : 'bg-white/5 text-brand-100/80 ring-1 ring-inset ring-white/10 hover:ring-white/25'">
                            @if ($isCheck)
                                <span class="w-[18px] h-[18px] shrink-0 rounded flex items-center justify-center transition-colors"
                                      :class="has(@js($option)) ? 'bg-brand-400 text-brand-900' : 'ring-1 ring-inset ring-white/25'">
                                    <svg x-show="has(@js($option))" x-cloak class="w-3 h-3" fill="none" viewBox="0 0 24 24"
                                         stroke="currentColor" stroke-width="3.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                    </svg>
                                </span>
                            @endif
                            {{ $option }}
                        </button>
                    @endforeach
                </div>

                {{-- Hidden inputs, rebuilt from the selection. A multi posts an
                     array; a single posts one value. --}}
                <template x-for="one in selected" :key="one">
                    <input type="hidden" :name="multi ? name + '[]' : name" :value="one">
                </template>

                @if ($q['other'] ?? false)
                    <div x-show="has('Other')" x-cloak>
                        <input type="text" name="answers[{{ $otherKey }}]" value="{{ $otherValue }}"
                               placeholder="Tell us more"
                               class="{{ $fieldCls }} max-w-sm">
                    </div>
                @endif
            </div>
            @break

        @case (BrandBrief::TYPE_URLS)
            @php $rows = array_values(array_filter((array) ($value ?? []))); @endphp
            <div x-data="urlList({ rows: @js($rows ?: ['', '']), max: {{ BrandBrief::MAX_URLS }} })" class="grid gap-2">
                <template x-for="(row, i) in rows" :key="i">
                    <div class="flex gap-2 items-start">
                        <div class="flex-1 min-w-0">
                            <input type="url" name="answers[{{ $key }}][]" x-model="rows[i]"
                                   placeholder="{{ $q['placeholder'] ?? 'https://' }}"
                                   class="{{ $fieldCls }}"
                                   :class="row && !valid(row) ? 'ring-red-400/60' : ''">
                            <p x-show="row && !valid(row)" x-cloak class="mt-1 text-xs text-red-300">
                                Enter a full link, e.g. https://instagram.com/yourbrand
                            </p>
                        </div>
                        <button type="button" x-show="rows.length > 1" @click="rows.splice(i, 1)"
                                aria-label="Remove link"
                                class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg text-brand-100/40 hover:text-white hover:bg-white/5">
                            <x-icon name="plus" class="w-4 h-4 rotate-45" />
                        </button>
                    </div>
                </template>
                <div>
                    <button type="button" x-show="rows.length < max" @click="rows.push('')"
                            class="inline-flex items-center gap-1.5 min-h-[44px] px-2 text-xs font-semibold uppercase tracking-widest text-brand-300 hover:text-white">
                        <x-icon name="plus" class="w-4 h-4" /> Add another
                    </button>
                </div>
            </div>
            @break

        @case (BrandBrief::TYPE_CONTACT)
            @php $contact = is_array($value) ? $value : []; @endphp
            <div class="grid gap-3 sm:grid-cols-3">
                @foreach (BrandBrief::CONTACT_FIELDS as $field => $meta)
                    <div>
                        <label class="block text-xs text-brand-100/50 mb-1">{{ $meta['label'] }}</label>
                        <input type="{{ $field === 'email' ? 'email' : ($field === 'phone' ? 'tel' : 'text') }}"
                               name="answers[{{ $key }}][{{ $field }}]"
                               value="{{ $contact[$field] ?? '' }}"
                               placeholder="{{ $meta['placeholder'] }}"
                               autocomplete="{{ $field === 'name' ? 'name' : $field }}"
                               class="{{ $fieldCls }}">
                        <x-input-error :messages="$errors->get('answers.'.$key.'.'.$field)" class="mt-1" />
                    </div>
                @endforeach
            </div>
            @break

    @endswitch

    @if ($error)
        <p class="text-xs text-red-300">{{ $error }}</p>
    @endif
</div>
