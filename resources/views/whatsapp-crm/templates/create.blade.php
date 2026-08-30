<x-app-layout title="New template">
    <x-slot name="header">
        <x-page-header title="New Template" eyebrow="WhatsApp CRM"
                       subtitle="Submitted to Meta for approval -- usually a few minutes to a day before it can send." />
    </x-slot>

    <div class="max-w-2xl mx-auto">
        <x-card class="p-4 sm:p-6">
            <form method="POST" action="{{ route('whatsapp-crm.templates.store') }}">
                @csrf

                <div class="mb-6">
                    <x-input-label for="name" value="Template name" />
                    <x-text-input id="name" name="name" type="text" class="mt-1" required autofocus
                        value="{{ old('name') }}" placeholder="e.g. order_confirmation" />
                    <p class="text-xs text-brand-100/60 mt-1">Lowercase letters, digits and underscores only. Cannot be changed once submitted.</p>
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 mb-6">
                    <div>
                        <x-input-label for="category" value="Category" />
                        <x-select id="category" name="category" class="mt-1" required>
                            <option value="">Select...</option>
                            @foreach (['MARKETING' => 'Marketing', 'UTILITY' => 'Utility', 'AUTHENTICATION' => 'Authentication'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('category') === $value)>{{ $label }}</option>
                            @endforeach
                        </x-select>
                        <x-input-error :messages="$errors->get('category')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="language" value="Language" />
                        <x-select id="language" name="language" class="mt-1" required>
                            @foreach (['en_US' => 'English (US)', 'en_GB' => 'English (UK)', 'hi' => 'Hindi'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('language', 'en_US') === $value)>{{ $label }}</option>
                            @endforeach
                        </x-select>
                        <x-input-error :messages="$errors->get('language')" class="mt-2" />
                    </div>
                </div>

                <div class="mb-6">
                    <x-input-label for="header" value="Header (optional)" />
                    <x-text-input id="header" name="header" type="text" class="mt-1"
                        value="{{ old('header') }}" placeholder="e.g. Order Update" maxlength="60" />
                    <x-input-error :messages="$errors->get('header')" class="mt-2" />
                </div>

                <div class="mb-6">
                    <x-input-label for="body" value="Body" />
                    <textarea id="body" name="body" required rows="5" maxlength="1024"
                        placeholder="Hi {{1}}, your order {{2}} has shipped."
                        class="mt-1 bg-white/5 border-white/15 text-white placeholder:text-brand-100/40 focus:bg-white/[0.07] focus:border-brand-400 focus:ring-brand-400 rounded-md w-full">{{ old('body') }}</textarea>
                    <p class="text-xs text-brand-100/60 mt-1">
                        {{-- @{{ }} is Blade's own escape for a literal double-brace pair. --}}
                        Use @{{1}}, @{{2}}, ... in order for variables Meta fills in per-send (e.g. name, order number).
                    </p>
                    <x-input-error :messages="$errors->get('body')" class="mt-2" />
                </div>

                <div class="mb-6">
                    <x-input-label for="body_example" value="Example values for the placeholders above" />
                    <x-text-input id="body_example" name="body_example" type="text" class="mt-1"
                        value="{{ old('body_example') }}" placeholder="e.g. Priya, ORD-1042" />
                    <p class="text-xs text-brand-100/60 mt-1">
                        One per @{{n}}, comma-separated, in order. Required if the body has any -- Meta needs a sample to review the template at all.
                    </p>
                    <x-input-error :messages="$errors->get('body_example')" class="mt-2" />
                </div>

                <div class="mb-6">
                    <x-input-label for="footer" value="Footer (optional)" />
                    <x-text-input id="footer" name="footer" type="text" class="mt-1"
                        value="{{ old('footer') }}" placeholder="e.g. Chakra Groups" maxlength="60" />
                    <x-input-error :messages="$errors->get('footer')" class="mt-2" />
                </div>

                <div class="flex flex-wrap items-center gap-4">
                    <x-primary-button>Submit for approval</x-primary-button>
                    <a href="{{ route('whatsapp-crm.templates.index') }}" class="text-sm text-brand-100/70 hover:text-white">Cancel</a>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
