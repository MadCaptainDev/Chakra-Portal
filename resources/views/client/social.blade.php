<x-app-layout title="Social" dark>
    <div class="space-y-6">

        <div class="animate-rise-in">
            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-300">{{ $client->name }}</p>
            <h1 class="mt-2 text-3xl sm:text-4xl font-extrabold tracking-tight">Social</h1>
            <p class="mt-2 text-sm text-brand-100/70">Connect your own Instagram so Chakra Groups can pull your analytics.</p>
        </div>

        {{-- Same card the staff side shows on a client's page, in self-service
             mode: this client's connect/disconnect routes, no {client} in
             either, and no staff-only View Analytics / Monthly Report links.
             See clients/_social.blade.php's own doc block for why one
             partial serves both screens. --}}
        @include('clients._social', [
            'client' => $client,
            'selfService' => true,
            'connectRoute' => route('client.instagram.connect'),
            'disconnectRoute' => route('client.instagram.disconnect'),
        ])
    </div>
</x-app-layout>
