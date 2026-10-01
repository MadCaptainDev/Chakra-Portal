{{--
    The Chakra Productions logo, from the studio's own artwork
    (public/images/logo/: chakra.svg, productions.svg, camera.svg), assembled
    as the logo is: CHAKRA top left, the camera to its right with the tripod
    standing behind PRODUCTIONS, PRODUCTIONS across the full width below and
    the tripod's feet just showing under it.

    Positions are in a 664×345 frame -- PRODUCTIONS' own width -- as
    percentages, so the lockup holds at any size:
      CHAKRA       440 wide at (0, 0)
      camera       179 wide at (470, -8)   (190×360 artwork, scaled to 340 tall)
      PRODUCTIONS  664 wide at (0, 172)
    Each part is its own layer so the film can move it; the extras around
    them (light, bolt, impact rings) are what the intro animates with.
--}}
<div data-sr="logo" class="relative w-[min(92vw,640px)] aspect-[664/345]">
    {{-- The light that comes on behind the camera with the lightning. --}}
    <div data-sr="camera-light" class="absolute left-[62%] -top-[16%] w-[44%] h-[86%] rounded-full bg-[radial-gradient(circle,rgba(228,242,247,0.9)_0%,rgba(103,188,212,0.55)_38%,rgba(103,188,212,0)_70%)] blur-md"></div>
    <svg data-sr="bolt" viewBox="0 0 40 90" class="absolute left-[74%] -top-[60%] w-[13%] h-[72%] text-white drop-shadow-[0_0_14px_rgba(171,218,231,1)]">
        <path d="M24 0 L6 48 H20 L12 90 L36 36 H22 L32 0 Z" fill="currentColor" />
    </svg>

    {{-- The camera is black artwork: a rim of light keeps its silhouette
         readable once the ground is no longer black. --}}
    <img data-sr="camera" src="{{ asset('images/logo/camera.svg') }}" alt="" draggable="false"
         class="absolute left-[70.8%] -top-[2.3%] w-[27%] [filter:drop-shadow(0_0_1.5px_rgba(171,218,231,0.95))_drop-shadow(0_0_14px_rgba(103,188,212,0.55))]"
         style="transform-origin: 50% 30%">

    <img data-sr="logo-productions" src="{{ asset('images/logo/productions.svg') }}" alt="Productions" draggable="false"
         class="absolute left-0 top-[49.86%] w-full">
    <img data-sr="logo-chakra" src="{{ asset('images/logo/chakra.svg') }}" alt="Chakra" draggable="false"
         class="absolute left-0 top-0 w-[66.27%]">

    {{-- Impact rings, one where each word lands. --}}
    <span data-sr="impact-chakra" class="absolute left-[33%] top-[26%] w-[40%] aspect-square -translate-x-1/2 -translate-y-1/2 pointer-events-none">
        <span class="block w-full h-full rounded-full border-2 border-brand-300/80 opacity-0" data-sr="impact-chakra-ring"></span>
    </span>
    <span data-sr="impact-productions" class="absolute left-[50%] top-[72%] w-[54%] aspect-square -translate-x-1/2 -translate-y-1/2 pointer-events-none">
        <span class="block w-full h-full rounded-full border-2 border-brand-300/80 opacity-0" data-sr="impact-productions-ring"></span>
    </span>
</div>
