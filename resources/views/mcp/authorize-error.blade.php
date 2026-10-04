<x-guest-layout title="Can't connect">
    <h2 class="text-2xl sm:text-3xl font-extrabold">Can't connect this app</h2>
    <p class="mt-3 text-sm text-brand-100/80">{{ $problem }}</p>
    <p class="mt-6 text-xs text-brand-100/60">Help with connecting: <a href="{{ route('developer.index') }}" class="text-brand-300 hover:text-brand-200">Developer</a> in the portal.</p>
</x-guest-layout>
