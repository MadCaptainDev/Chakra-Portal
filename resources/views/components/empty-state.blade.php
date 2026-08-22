@props(['message' => 'Nothing here yet.'])

{{--
    One illustration for every "nothing here" screen in the app -- from an
    empty timesheet to an empty client list. Deliberately generic (an open
    tray, not a camera or a film reel): this renders in contexts that have
    nothing to do with video production, so anything more literal would be
    wrong somewhere it appears. animate-rise-in is the same one-time settle
    every other first-paint element in the app uses, already silenced under
    prefers-reduced-motion.
--}}
<div class="text-center py-12 px-4 animate-rise-in">
    <svg class="mx-auto w-24 h-24" viewBox="0 0 120 120" fill="none" aria-hidden="true">
        <circle cx="60" cy="60" r="52" fill="#F2F9FC" />

        <circle cx="34" cy="34" r="4" fill="#8ACCE0" opacity=".8" />
        <circle cx="88" cy="30" r="3" fill="#67BCD4" opacity=".7" />
        <circle cx="90" cy="70" r="2.5" fill="#4FA9C4" opacity=".6" />

        <path d="M32 52h56l-6 30a6 6 0 01-6 5H44a6 6 0 01-6-5l-6-30z"
              fill="#E4F2F7" stroke="#ABDAE7" stroke-width="2.5" stroke-linejoin="round" />
        <path d="M32 52l6-16a4 4 0 014-3h36a4 4 0 014 3l6 16"
              fill="none" stroke="#ABDAE7" stroke-width="2.5" stroke-linejoin="round" />
        <line x1="40" y1="63" x2="80" y2="63" stroke="#8ACCE0" stroke-width="2.5" stroke-linecap="round" />
    </svg>
    <p class="mt-3 text-sm text-gray-500">{{ $message }}</p>
    @if (! $slot->isEmpty())
        <div class="mt-4">{{ $slot }}</div>
    @endif
</div>
