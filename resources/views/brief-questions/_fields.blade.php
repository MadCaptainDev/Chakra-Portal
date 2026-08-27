@php
    use App\Support\BrandBrief;

    /*
     * The add/edit fields for one custom question, shared by both forms.
     *
     * The options box only matters for the two list types, so Alpine hides it
     * for the others rather than leaving a textarea on screen that quietly
     * does nothing.
     *
     * $question  BriefQuestion|null
     * $stepId    the group when adding; taken from the question when editing
     */
    $question = $question ?? null;
    $type = old('type', $question?->type ?? BrandBrief::TYPE_TEXTAREA);
    $listTypes = [BrandBrief::TYPE_CHIPS, BrandBrief::TYPE_CHECKS];
@endphp

<div x-data="{ type: @js($type) }" class="grid gap-3">
    <div>
        <x-input-label value="The question, as the client reads it" />
        <x-text-input name="label" type="text" class="mt-1 w-full"
                      value="{{ old('label', $question?->label) }}"
                      placeholder="Do you have parking at the shop?" required />
        <x-input-error :messages="$errors->get('label')" class="mt-1" />
    </div>

    <div class="grid sm:grid-cols-2 gap-3">
        <div>
            <x-input-label value="Answer type" />
            <select name="type" x-model="type"
                    class="mt-1 block w-full rounded-md border-white/15 text-sm focus:border-brand-500 focus:ring-brand-500">
                @foreach ($types as $value => $label)
                    <option value="{{ $value }}" @selected($type === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <x-input-error :messages="$errors->get('type')" class="mt-1" />
        </div>

        <div>
            <x-input-label value="Hint under the question (optional)" />
            <x-text-input name="help" type="text" class="mt-1 w-full"
                          value="{{ old('help', $question?->help) }}"
                          placeholder="Pick as many as apply." />
        </div>
    </div>

    <div x-show="@js($listTypes).includes(type)" x-cloak>
        <x-input-label value="Options, one per line" />
        <textarea name="options" rows="5"
                  class="mt-1 block w-full rounded-md border-white/15 text-sm focus:border-brand-500 focus:ring-brand-500"
                  placeholder="Yes&#10;No&#10;Sometimes">{{ old('options', $question?->options ? implode("\n", $question->options) : '') }}</textarea>
        <x-input-error :messages="$errors->get('options')" class="mt-1" />

        <label class="mt-2 inline-flex items-center gap-2 text-sm text-brand-100/80">
            <input type="checkbox" name="multi" value="1" @checked(old('multi', $question?->multi))
                   class="rounded border-white/15 text-brand-300 focus:ring-brand-500">
            Allow more than one answer
        </label>
    </div>

    <label class="inline-flex items-center gap-2 text-sm text-brand-100/80">
        <input type="checkbox" name="required" value="1" @checked(old('required', $question?->required))
               class="rounded border-white/15 text-brand-300 focus:ring-brand-500">
        Required — the client cannot submit without it
    </label>
</div>
