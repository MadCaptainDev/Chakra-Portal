<x-app-layout title="Settings">
    <x-slot name="header">
        <x-page-header title="Company Settings" />
    </x-slot>

    <div class="max-w-2xl mx-auto">
        <x-card class="p-4 sm:p-6">
            <form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data">
                @csrf
                @method('PUT')

                <div class="mb-4">
                    <x-input-label for="company_name" value="Company Name" />
                    <x-text-input id="company_name" name="company_name" type="text" class="mt-1" value="{{ old('company_name', $settings->company_name) }}" required />
                    <x-input-error :messages="$errors->get('company_name')" class="mt-2" />
                </div>

                <div class="mb-4">
                    <x-input-label for="address" value="Company Address" />
                    <x-text-input id="address" name="address" type="text" class="mt-1" value="{{ old('address', $settings->address) }}" />
                    <x-input-error :messages="$errors->get('address')" class="mt-2" />
                </div>

                <div class="mb-4">
                    <x-input-label for="logo" value="Logo" />
                    <img src="{{ asset($settings->logo_path) }}" alt="Current logo" class="h-12 my-2">
                    <input id="logo" name="logo" type="file" accept="image/*" class="mt-1 block w-full text-sm">
                    <x-input-error :messages="$errors->get('logo')" class="mt-2" />
                </div>

                <div class="mb-4">
                    <x-input-label for="signature_name" value="Signature Name" />
                    <x-text-input id="signature_name" name="signature_name" type="text" class="mt-1" value="{{ old('signature_name', $settings->signature_name) }}" required />
                    <x-input-error :messages="$errors->get('signature_name')" class="mt-2" />
                </div>

                <div class="mb-4">
                    <x-input-label for="signature_title" value="Signature Title" />
                    <x-text-input id="signature_title" name="signature_title" type="text" class="mt-1" value="{{ old('signature_title', $settings->signature_title) }}" required />
                    <x-input-error :messages="$errors->get('signature_title')" class="mt-2" />
                </div>

                <div class="mb-4">
                    <x-input-label for="invoice_prefix" value="Invoice Number Prefix" />
                    <x-text-input id="invoice_prefix" name="invoice_prefix" type="text" class="mt-1" value="{{ old('invoice_prefix', $settings->invoice_prefix) }}" required />
                    <x-input-error :messages="$errors->get('invoice_prefix')" class="mt-2" />
                </div>

                <div class="mb-4">
                    <x-input-label for="footer_text" value="Footer Thank-You Text" />
                    <x-text-input id="footer_text" name="footer_text" type="text" class="mt-1" value="{{ old('footer_text', $settings->footer_text) }}" required />
                    <x-input-error :messages="$errors->get('footer_text')" class="mt-2" />
                </div>

                <div class="mb-6">
                    <x-input-label for="notification_email" value="Notification Email (optional)" />
                    <x-text-input id="notification_email" name="notification_email" type="email" class="mt-1" value="{{ old('notification_email', $settings->notification_email) }}" />
                    <x-input-error :messages="$errors->get('notification_email')" class="mt-2" />
                    <p class="text-xs text-gray-500 mt-1">Where to send alerts when recurring invoices are generated. Leave blank to notify every staff account.</p>
                </div>

                <x-primary-button>Save</x-primary-button>
            </form>
        </x-card>

    </div>
</x-app-layout>
