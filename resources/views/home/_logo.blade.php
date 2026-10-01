{{--
    The Chakra Productions logo, drawn as vectors so it stays sharp at any
    size -- the PNG in images/ is 114×60 and blurs when the film blows it up.

    The same lockup as that file: CHAKRA top left, the film camera top right
    with its tripod behind PRODUCTIONS, which runs the full width. White
    letters in a heavy condensed face (Anton) inside a thick brand-400
    outline, with a darker brand-700 edge offset under them for depth. The
    camera is drawn light here (it is black in the PNG) so it reads on navy.

    Three layers sharing one 620×330 frame, so the film can move each part on
    its own. textLength pins every word to its width, so the lockup holds
    even if the web font is slow and a fallback face draws it.
--}}
@php
    $word = 'font-family: Anton, Impact, \'Arial Narrow Bold\', sans-serif; letter-spacing: 2px';
@endphp
<div data-sr="logo" class="relative w-[min(90vw,620px)] aspect-[620/330]">
    {{-- The camera's backlight, and the lightning that brings it in. --}}
    <div data-sr="camera-light" class="absolute left-[62%] -top-[14%] w-[44%] h-[84%] rounded-full bg-[radial-gradient(circle,rgba(228,242,247,0.85)_0%,rgba(103,188,212,0.5)_40%,rgba(103,188,212,0)_70%)] blur-md"></div>
    <svg data-sr="bolt" viewBox="0 0 40 90" class="absolute left-[70%] -top-[58%] w-[14%] h-[74%] text-white drop-shadow-[0_0_12px_rgba(171,218,231,0.95)]">
        <path d="M24 0 L6 48 H20 L12 90 L36 36 H22 L32 0 Z" fill="currentColor" />
    </svg>

    {{-- The camera: two film reels, body, lens, crank, tripod. --}}
    <svg data-sr="camera" viewBox="0 0 620 330" class="absolute inset-0 w-full h-full overflow-visible" style="transform-origin: 85% 30%">
        <g transform="translate(436 4) scale(1.46)" fill="#E4F2F7">
            {{-- Tripod first, so the body sits on it. --}}
            <g stroke="#E4F2F7" stroke-width="5" stroke-linecap="round">
                <line x1="60" y1="96" x2="34" y2="146" />
                <line x1="60" y1="96" x2="60" y2="148" />
                <line x1="60" y1="96" x2="86" y2="146" />
            </g>
            <rect x="48" y="86" width="24" height="12" rx="3" />
            {{-- Reels. --}}
            @foreach ([[38, 26, 21], [80, 22, 23]] as [$cx, $cy, $r])
                <circle cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $r }}" />
                <circle cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $r * 0.24 }}" fill="#132A38" />
                @for ($i = 0; $i < 5; $i++)
                    <circle cx="{{ $cx + cos(deg2rad($i * 72 - 90)) * $r * 0.56 }}" cy="{{ $cy + sin(deg2rad($i * 72 - 90)) * $r * 0.56 }}" r="{{ $r * 0.17 }}" fill="#132A38" />
                @endfor
            @endforeach
            {{-- Body, lens, crank. --}}
            <rect x="20" y="46" width="72" height="42" rx="6" />
            <rect x="28" y="54" width="22" height="6" rx="3" fill="#67BCD4" />
            <path d="M92 58 L116 46 L116 88 L92 76 Z" />
            <g stroke="#E4F2F7" stroke-width="5" stroke-linecap="round">
                <line x1="20" y1="76" x2="4" y2="84" />
            </g>
        </g>
    </svg>

    {{-- PRODUCTIONS, full width under everything. --}}
    <svg data-sr="logo-productions" viewBox="0 0 620 330" class="absolute inset-0 w-full h-full overflow-visible">
        <g style="{{ $word }}" font-size="124">
            <text x="16" y="318" textLength="596" lengthAdjust="spacingAndGlyphs" fill="#2F6E84" stroke="#2F6E84" stroke-width="22" stroke-linejoin="round">PRODUCTIONS</text>
            <text x="10" y="310" textLength="596" lengthAdjust="spacingAndGlyphs" fill="#4FA9C4" stroke="#4FA9C4" stroke-width="18" stroke-linejoin="round">PRODUCTIONS</text>
            <text x="10" y="310" textLength="596" lengthAdjust="spacingAndGlyphs" fill="#FFFFFF">PRODUCTIONS</text>
        </g>
    </svg>

    {{-- CHAKRA, top left. --}}
    <svg data-sr="logo-chakra" viewBox="0 0 620 330" class="absolute inset-0 w-full h-full overflow-visible">
        <g style="{{ $word }}" font-size="156">
            <text x="16" y="168" textLength="398" lengthAdjust="spacingAndGlyphs" fill="#2F6E84" stroke="#2F6E84" stroke-width="22" stroke-linejoin="round">CHAKRA</text>
            <text x="10" y="160" textLength="398" lengthAdjust="spacingAndGlyphs" fill="#4FA9C4" stroke="#4FA9C4" stroke-width="18" stroke-linejoin="round">CHAKRA</text>
            <text x="10" y="160" textLength="398" lengthAdjust="spacingAndGlyphs" fill="#FFFFFF">CHAKRA</text>
        </g>
    </svg>
</div>
