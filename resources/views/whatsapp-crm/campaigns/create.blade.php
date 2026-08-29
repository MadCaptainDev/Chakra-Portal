@php
    // The bits Alpine needs client-side: which template is picked drives how
    // many variable-mapping rows show and what language gets submitted along
    // with it -- both live only in this array, not duplicated into data
    // attributes per <option>.
    $templateOptions = collect($templates)->map(fn ($template) => [
        'name' => $template['name'] ?? '',
        'language' => $template['language'] ?? 'en_US',
        'placeholderCount' => $template['placeholder_count'] ?? 0,
    ])->values();
@endphp

<x-app-layout title="New campaign">
    <x-slot name="header">
        <x-page-header title="New Campaign" eyebrow="WhatsApp CRM"
                       subtitle="Send one approved template to everyone in a phonebook." />
    </x-slot>

    <div class="max-w-2xl mx-auto">
        <x-card class="p-4 sm:p-6">
            @if ($templates === [])
                <p class="text-sm text-brand-100/70">
                    No approved templates yet.
                    <a href="{{ route('whatsapp-crm.templates.index') }}" class="text-brand-500 hover:text-brand-300 font-semibold">Check Templates</a>
                    -- a campaign needs one to send.
                </p>
            @elseif ($phonebooks->isEmpty())
                <p class="text-sm text-brand-100/70">
                    No phonebooks yet.
                    <a href="{{ route('whatsapp-crm.phonebooks.create') }}" class="text-brand-500 hover:text-brand-300 font-semibold">Create one</a>
                    -- a campaign needs contacts to send to.
                </p>
            @else
                <form method="POST" action="{{ route('whatsapp-crm.campaigns.store') }}"
                      x-data="{
                          templates: @js($templateOptions),
                          selected: '{{ old('meta_template_name', $templateOptions->first()['name'] ?? '') }}',
                          mapping: @js($initialMapping),
                          get template() { return this.templates.find(t => t.name === this.selected) ?? null },
                          get paramCount() { return this.template ? this.template.placeholderCount : 0 },
                      }">
                    @csrf

                    <div class="mb-6">
                        <x-input-label for="name" value="Campaign name" />
                        <x-text-input id="name" name="name" type="text" class="mt-1" required autofocus
                            value="{{ old('name') }}" placeholder="e.g. August Booking Reminder" />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <div class="mb-6">
                        <x-input-label for="phonebook_id" value="Phonebook" />
                        <x-select id="phonebook_id" name="phonebook_id" class="mt-1" required>
                            <option value="">Select a phonebook...</option>
                            @foreach ($phonebooks as $phonebook)
                                <option value="{{ $phonebook->id }}" @selected(old('phonebook_id') == $phonebook->id)>
                                    {{ $phonebook->name }} ({{ $phonebook->contacts_count }} contact{{ $phonebook->contacts_count === 1 ? '' : 's' }})
                                </option>
                            @endforeach
                        </x-select>
                        <x-input-error :messages="$errors->get('phonebook_id')" class="mt-2" />
                    </div>

                    <div class="mb-6">
                        <x-input-label for="meta_template_name" value="Template" />
                        <x-select id="meta_template_name" name="meta_template_name" class="mt-1" required x-model="selected">
                            @foreach ($templates as $template)
                                <option value="{{ $template['name'] }}" @selected(old('meta_template_name') === $template['name'])>
                                    {{ $template['name'] }} ({{ $template['language'] }})
                                </option>
                            @endforeach
                        </x-select>
                        <x-input-error :messages="$errors->get('meta_template_name')" class="mt-2" />
                        {{-- The language travels with whichever template is picked, not typed
                             separately -- one fewer field to get out of sync with the template. --}}
                        <input type="hidden" name="meta_template_language" :value="template ? template.language : '{{ old('meta_template_language', 'en_US') }}'">
                    </div>

                    <div class="mb-6" x-show="paramCount > 0" x-cloak>
                        <x-input-label value="Placeholders" />
                        <p class="text-xs text-brand-100/60 mt-1 mb-2">
                            {{-- @{{ }} is Blade's own escape for a literal double-brace pair --
                                 writing the real "{{n}}" here would look like an (unclosed) Blade
                                 echo to the compiler, brace-nesting and all. --}}
                            For each @{{n}} in the template's body, use one of the contact's merge fields or fixed text.
                        </p>
                        <div class="space-y-2">
                            @for ($i = 0; $i < 5; $i++)
                                @php($placeholderLabel = '{{'.($i + 1).'}}')
                                <div class="flex items-center gap-2" x-show="paramCount > {{ $i }}">
                                    {{-- Built in PHP above rather than inline, for the same reason
                                         as the @{{n}} comment just above -- the raw "{{"/"}}" characters
                                         would otherwise sit inside this very echo's source text. --}}
                                    <span class="text-xs text-brand-100/60 w-8 shrink-0">{{ $placeholderLabel }}</span>
                                    <select x-model="mapping[{{ $i }}].mode"
                                            class="bg-white/5 border-white/15 text-white focus:bg-white/[0.07] focus:border-brand-400 focus:ring-brand-400 rounded-md min-h-[44px] w-full">
                                        <option value="var1">Contact's Var 1</option>
                                        <option value="var2">Contact's Var 2</option>
                                        <option value="var3">Contact's Var 3</option>
                                        <option value="var4">Contact's Var 4</option>
                                        <option value="var5">Contact's Var 5</option>
                                        <option value="literal">Fixed text...</option>
                                    </select>
                                    <input type="text" placeholder="Fixed text"
                                           x-show="mapping[{{ $i }}].mode === 'literal'"
                                           x-model="mapping[{{ $i }}].value"
                                           class="bg-white/5 border-white/15 text-white placeholder:text-brand-100/40 focus:bg-white/[0.07] focus:border-brand-400 focus:ring-brand-400 rounded-md min-h-[44px] w-full">
                                    {{-- Only this hidden input is actually submitted -- the select
                                         above is UI, this is the one value/name pair the controller
                                         reads (see WhatsappCampaignController::store()). --}}
                                    <input type="hidden" name="variable_mapping[{{ $i }}]"
                                           :value="mapping[{{ $i }}].mode === 'literal' ? mapping[{{ $i }}].value : mapping[{{ $i }}].mode"
                                           :disabled="paramCount <= {{ $i }}">
                                </div>
                            @endfor
                        </div>
                        <x-input-error :messages="$errors->get('variable_mapping')" class="mt-2" />
                    </div>

                    <div class="mb-6">
                        <x-input-label for="scheduled_at" value="Schedule for later (optional)" />
                        <x-text-input id="scheduled_at" name="scheduled_at" type="datetime-local" class="mt-1"
                            value="{{ old('scheduled_at') }}" />
                        <x-input-error :messages="$errors->get('scheduled_at')" class="mt-2" />
                        <p class="text-xs text-brand-100/60 mt-1">Leave blank to send as soon as possible (within a minute).</p>
                    </div>

                    <div class="flex flex-wrap items-center gap-4">
                        <x-primary-button>Create Campaign</x-primary-button>
                        <a href="{{ route('whatsapp-crm.campaigns.index') }}" class="text-sm text-brand-100/70 hover:text-white">Cancel</a>
                    </div>
                </form>
            @endif
        </x-card>
    </div>
</x-app-layout>
