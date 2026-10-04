<x-guest-layout title="Connect an AI app">
    <h2 class="text-2xl sm:text-3xl font-extrabold">Connect {{ $client->name }}?</h2>
    <p class="mt-2.5 text-sm text-brand-100/70">
        <strong class="text-white">{{ $client->name }}</strong> wants to use the Chakra Portal as
        <strong class="text-white">{{ $user->name }}</strong>.
    </p>

    <ul class="mt-6 space-y-2.5 text-sm text-brand-100/80">
        <li class="flex gap-2.5"><span class="text-emerald-300">✓</span> It can only see and do what you can in the portal — your permissions, never more.</li>
        <li class="flex gap-2.5"><span class="text-emerald-300">✓</span> It can draft quotations, plan shoots and read reports, and can WhatsApp a client only when you ask it to.</li>
        <li class="flex gap-2.5"><span class="text-emerald-300">✓</span> It can't record payments, see client passwords or delete clients.</li>
        <li class="flex gap-2.5"><span class="text-emerald-300">✓</span> Every action is logged. Remove it any time under Developer → Tokens.</li>
    </ul>

    <p class="mt-6 text-xs text-brand-100/50">After you allow, you'll go back to {{ $redirectHost }}.</p>

    <form method="POST" action="{{ route('mcp.oauth.decide') }}" class="mt-6 flex flex-col sm:flex-row gap-3">
        @csrf
        @foreach ($params as $key => $value)
            @if (is_string($value))
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endif
        @endforeach
        <input type="hidden" name="response_type" value="code">

        <x-auth-button class="sm:flex-1" name="decision" value="allow">Allow</x-auth-button>
        <button type="submit" name="decision" value="deny"
                class="sm:flex-1 inline-flex items-center justify-center min-h-[48px] px-5 rounded-lg border border-white/15 text-sm font-semibold text-brand-100/80 hover:bg-white/5 transition-colors">
            Deny
        </button>
    </form>
</x-guest-layout>
