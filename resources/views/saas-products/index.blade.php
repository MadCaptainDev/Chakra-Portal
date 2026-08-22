<x-app-layout title="SaaS Products">
    <x-slot name="header">
        <x-page-header title="SaaS Products" eyebrow="App Studio"
                       subtitle="Client-built software Chakra maintains — backups, restore versions, and the AMC that keeps each one running." />
    </x-slot>

    <div class="space-y-4">
        {{-- Chakra App Studio's own income, apart from Production's --
             every invoice tagged with a saas_product_id, whichever product
             it belongs to. See invoices/_form.blade.php for where that tag
             gets set. --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <x-stat-card label="App Studio income this month" :value="number_format($monthAmcIncome, 2)" accent="green" icon="trending-up" />
            <x-stat-card label="App Studio income, all time" :value="number_format($totalAmcIncome, 2)" accent="brand" icon="wallet" />
        </div>

        @can('saas-products.create')
            <x-card padding="md">
                <x-section-heading title="Onboard a new product" subtitle="One row per piece of software Chakra Studio built and maintains." />

                <form method="POST" action="{{ route('saas-products.store') }}" class="grid grid-cols-1 sm:grid-cols-[1fr_1fr_auto_auto] gap-3 items-end">
                    @csrf
                    <div>
                        <x-input-label for="client_id" value="Client" />
                        <x-select id="client_id" name="client_id" class="mt-1 w-full">
                            <option value="">Select a client</option>
                            @foreach ($clients as $client)
                                <option value="{{ $client->id }}" @selected(old('client_id') == $client->id)>{{ $client->name }}</option>
                            @endforeach
                        </x-select>
                        <x-input-error :messages="$errors->get('client_id')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="name" value="Software name" />
                        <x-text-input id="name" name="name" type="text" class="mt-1 w-full"
                                      placeholder="e.g. DJ Thangamaaligai ERP" value="{{ old('name') }}" />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="backup_retention_count" value="Keep last" />
                        <x-text-input id="backup_retention_count" name="backup_retention_count" type="number" min="1" max="365"
                                      class="mt-1 w-24" placeholder="20" value="{{ old('backup_retention_count') }}" />
                    </div>
                    <x-btn type="submit" icon="plus">Onboard</x-btn>
                </form>
            </x-card>
        @endcan

        @if ($products->isEmpty())
            <x-empty-state message="Nothing onboarded yet." />
        @else
            <x-card class="divide-y divide-gray-100 overflow-hidden">
                @foreach ($products as $product)
                    <div class="p-4 flex flex-col sm:flex-row sm:items-center gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <a href="{{ route('saas-products.show', $product) }}" class="font-semibold text-gray-900 hover:text-brand-600">
                                    {{ $product->name }}
                                </a>
                                <x-badge :status="$product->status()" />
                            </div>
                            <p class="text-xs text-gray-500 mt-0.5">
                                {{ $product->client->name }}
                                &middot; {{ $product->backups_count }} {{ Str::plural('backup', $product->backups_count) }}
                                @if ($product->amc_paid_until)
                                    &middot; AMC paid until {{ $product->amc_paid_until->format('j M Y') }}
                                @else
                                    &middot; AMC not billed yet
                                @endif
                            </p>
                        </div>
                    </div>
                @endforeach
            </x-card>
        @endif
    </div>
</x-app-layout>
