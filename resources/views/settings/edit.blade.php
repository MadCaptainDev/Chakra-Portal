<x-settings-layout title="Settings">
    <x-slot name="header">
        <x-page-header title="Company Settings" />
    </x-slot>

    <div>
        <x-card class="p-4 sm:p-6">
            <form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-x-8 gap-y-4">
                    <div>
                        <x-input-label for="company_name" value="Company Name" />
                        <x-text-input id="company_name" name="company_name" type="text" class="mt-1 w-full" value="{{ old('company_name', $settings->company_name) }}" required />
                        <x-input-error :messages="$errors->get('company_name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="address" value="Company Address" />
                        <x-text-input id="address" name="address" type="text" class="mt-1 w-full" value="{{ old('address', $settings->address) }}" />
                        <x-input-error :messages="$errors->get('address')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="logo" value="Logo (Chakra Production)" />
                        <img src="{{ asset($settings->logo_path) }}" alt="Current logo" class="h-12 my-2">
                        <input id="logo" name="logo" type="file" accept="image/*" class="mt-1 block w-full text-sm">
                        <x-input-error :messages="$errors->get('logo')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="app_studio_logo" value="Logo (Chakra App Studio)" />
                        @if ($settings->app_studio_logo_path)
                            <img src="{{ asset($settings->app_studio_logo_path) }}" alt="Current App Studio logo" class="h-12 my-2">
                        @else
                            <p class="text-xs text-brand-100/60 my-2">Not set yet -- App Studio invoices use the Production logo above until one is uploaded.</p>
                        @endif
                        <input id="app_studio_logo" name="app_studio_logo" type="file" accept="image/*" class="mt-1 block w-full text-sm">
                        <x-input-error :messages="$errors->get('app_studio_logo')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="signature_name" value="Signature Name" />
                        <x-text-input id="signature_name" name="signature_name" type="text" class="mt-1 w-full" value="{{ old('signature_name', $settings->signature_name) }}" required />
                        <x-input-error :messages="$errors->get('signature_name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="signature_title" value="Signature Title" />
                        <x-text-input id="signature_title" name="signature_title" type="text" class="mt-1 w-full" value="{{ old('signature_title', $settings->signature_title) }}" required />
                        <x-input-error :messages="$errors->get('signature_title')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="invoice_prefix" value="Invoice Number Prefix" />
                        <x-text-input id="invoice_prefix" name="invoice_prefix" type="text" class="mt-1 w-full" value="{{ old('invoice_prefix', $settings->invoice_prefix) }}" required />
                        <x-input-error :messages="$errors->get('invoice_prefix')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="footer_text" value="Footer Thank-You Text" />
                        <x-text-input id="footer_text" name="footer_text" type="text" class="mt-1 w-full" value="{{ old('footer_text', $settings->footer_text) }}" required />
                        <x-input-error :messages="$errors->get('footer_text')" class="mt-2" />
                    </div>

                    <div class="lg:col-span-2">
                        <x-input-label for="notification_email" value="Notification Email (optional)" />
                        <x-text-input id="notification_email" name="notification_email" type="email" class="mt-1 w-full lg:w-1/2" value="{{ old('notification_email', $settings->notification_email) }}" />
                        <x-input-error :messages="$errors->get('notification_email')" class="mt-2" />
                        <p class="text-xs text-brand-100/60 mt-1">Where to send alerts when recurring invoices are generated. Leave blank to notify every staff account.</p>
                    </div>
                </div>

                <x-primary-button class="mt-6">Save</x-primary-button>
            </form>
        </x-card>
    </div>
</x-settings-layout>
