{{--
    How we work: the six steps as a scroll-driven animation
    (initProcess in resources/js/showreel.js).

    The stage is pinned while a tall track scrolls past, like the film. Each
    step gets a stretch of the scroll; its words cross-fade in, and its
    illustration draws itself as you go -- the bulb lights, a pen writes the
    script line by line, the clapperboard snaps, clips land on a timeline,
    comments pop up, the post uploads. Behind it, giant step numbers and
    soft lights drift at their own speeds.

    How the illustrations move is declared here, on the elements:
      data-a   what it does -- draw (a stroke being drawn), fade, pop, rise,
               slide, grow (bars), scalex (a progress bar), move (data-dx
               across), clap (swings shut), flash, float (drifts up), pen
               (follows whichever data-pen line is being drawn)
      data-at  "from,to": when, as a fraction of the step's own scroll
    Everything is at its finished state until the script runs, so without
    it -- or for someone who asked for less motion -- nothing is half-drawn.

    Classes the script toggles, named here so Tailwind builds them:
    opacity-0 translate-y-4 -translate-y-4 text-white text-brand-100/40
--}}
@php
    $steps = [
        ['Idea', 'We agree the angle, the audience and what the piece has to do.'],
        ['Script', 'Written and signed off before anyone books a camera.'],
        ['Shoot', 'Planned shoot days against the script, so nothing is discovered on set.'],
        ['Edit', 'First cut, then revisions — you see it while it can still change cheaply.'],
        ['Review', 'Your notes, our final pass. Nothing ships without your sign-off.'],
        ['Publish', 'Delivered, scheduled and posted on the channels you want it on.'],
    ];

    // A handwritten line: a wave from x to x + length at height y.
    $scrawl = function (float $y, float $length, int $seed) {
        $d = 'M110 '.$y;
        for ($x = 0, $i = 0; $x < $length; $x += 12, $i++) {
            $d .= ' q6 '.(($i + $seed) % 3 === 0 ? -9 : -6).' 12 0';
        }

        return $d;
    };

    $heart = 'M0 6 C0 -2 10 -4 12 3 C14 -4 24 -2 24 6 C24 14 12 20 12 23 C12 20 0 14 0 6 Z';
@endphp

<section id="process" data-process class="relative scroll-mt-0 bg-brand-900" aria-labelledby="process-heading">
    {{-- For screen readers, and anyone without the animation: the steps as a list. --}}
    <ol class="sr-only">
        @foreach ($steps as [$title, $description])
            <li>{{ $title }}: {{ $description }}</li>
        @endforeach
    </ol>

    <div data-pr-track class="relative" style="height: 100vh; height: 100svh">
        <div data-pr-stage class="sticky top-0 overflow-hidden" style="height: 100vh; height: 100svh">
            {{-- Parallax: giant numbers and soft lights, each at its own speed. --}}
            <div class="absolute inset-0 pointer-events-none" aria-hidden="true">
                <div data-pr-blob="0.6" class="absolute -left-[20vw] top-[10%] w-[60vw] h-[60vw] max-w-[700px] max-h-[700px] rounded-full bg-brand-400/10 blur-3xl"></div>
                <div data-pr-blob="-0.9" class="absolute -right-[15vw] bottom-[5%] w-[50vw] h-[50vw] max-w-[600px] max-h-[600px] rounded-full bg-brand-600/15 blur-3xl"></div>
                @foreach ($steps as $i => $step)
                    <span data-pr-num="{{ $i }}" class="absolute right-[4vw] top-1/2 -translate-y-1/2 text-[clamp(10rem,45vw,30rem)] font-extrabold leading-none text-transparent [-webkit-text-stroke:2px_rgba(103,188,212,0.12)] select-none opacity-0">
                        {{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}
                    </span>
                @endforeach
            </div>

            <div class="relative h-full max-w-6xl mx-auto px-5 sm:px-8 pt-20 sm:pt-24 pb-6 sm:pb-10 flex flex-col lg:grid lg:grid-cols-2 lg:gap-12 lg:items-center">
                {{-- The words. --}}
                <div class="shrink-0">
                    <p class="text-brand-300 text-xs font-semibold uppercase tracking-[0.25em]">How we work</p>
                    <h2 id="process-heading" class="mt-2 text-xl sm:text-3xl lg:text-4xl font-bold leading-tight">Six steps, and you see it at every one.</h2>

                    <div class="relative mt-4 sm:mt-8 grid">
                        @foreach ($steps as $i => [$title, $description])
                            <div data-pr-text="{{ $i }}" class="[grid-area:1/1] transition duration-500 ease-out {{ $i === 0 ? '' : 'opacity-0 translate-y-4' }}">
                                <p class="flex items-baseline gap-3">
                                    <span class="text-brand-400 text-lg sm:text-2xl font-extrabold tabular-nums">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span>
                                    <span class="text-3xl sm:text-5xl lg:text-6xl font-extrabold tracking-tight">{{ $title }}</span>
                                </p>
                                <p class="mt-2 sm:mt-4 text-sm sm:text-lg text-brand-100/75 leading-relaxed max-w-md">{{ $description }}</p>
                            </div>
                        @endforeach
                    </div>

                    {{-- Where you are: the six steps, filling as you go. --}}
                    <ol class="hidden lg:flex mt-10 gap-2" aria-hidden="true">
                        @foreach ($steps as $i => [$title])
                            <li class="flex-1">
                                <span class="block h-1 rounded-full bg-white/10 overflow-hidden"><span data-pr-bar="{{ $i }}" class="block h-full bg-brand-400 origin-left scale-x-0"></span></span>
                                <span data-pr-label="{{ $i }}" class="mt-2 block text-xs font-semibold text-brand-100/40 transition-colors">{{ $title }}</span>
                            </li>
                        @endforeach
                    </ol>
                </div>

                {{-- The pictures. --}}
                <div class="relative flex-1 min-h-0 flex items-center justify-center mt-4 lg:mt-0">
                    <div class="relative aspect-square h-full max-h-[min(88vw,460px)] lg:max-h-[480px] w-auto max-w-full rounded-[2rem] bg-brand-800/50 ring-1 ring-white/10 shadow-2xl shadow-black/40 overflow-hidden">
                        <svg viewBox="0 0 400 400" class="absolute inset-0 w-full h-full" aria-hidden="true">
                            <defs>
                                <radialGradient id="pr-glow"><stop offset="0" stop-color="#FBBF24" stop-opacity="0.55" /><stop offset="1" stop-color="#FBBF24" stop-opacity="0" /></radialGradient>
                                <linearGradient id="pr-post" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#67BCD4" /><stop offset="1" stop-color="#2F6E84" /></linearGradient>
                            </defs>

                            {{-- 01 · Idea: the bulb draws itself and lights; notes stick up around it. --}}
                            <g data-pr-art="0">
                                <circle data-a="fade" data-at="0.35,0.7" cx="200" cy="160" r="130" fill="url(#pr-glow)" />
                                {{-- Seven rays: the one straight down would cross the bulb's base. --}}
                                @foreach ([0, 1, 2, 3, 5, 6, 7] as $r)
                                    @php $angle = deg2rad($r * 45 - 90); @endphp
                                    <line data-a="draw" data-at="{{ 0.45 + $r * 0.03 }},{{ 0.6 + $r * 0.03 }}" pathLength="1"
                                          x1="{{ 200 + cos($angle) * 112 }}" y1="{{ 160 + sin($angle) * 112 }}" x2="{{ 200 + cos($angle) * 140 }}" y2="{{ 160 + sin($angle) * 140 }}"
                                          stroke="#FBBF24" stroke-width="6" stroke-linecap="round" />
                                @endforeach
                                <path data-a="draw" data-at="0,0.4" pathLength="1" d="M200 78 a78 78 0 0 1 46 141 v25 h-92 v-25 a78 78 0 0 1 46 -141 z" fill="none" stroke="#FFFFFF" stroke-width="7" stroke-linejoin="round" />
                                <path data-a="draw" data-at="0.3,0.55" pathLength="1" d="M176 212 l10 -30 l14 22 l14 -22 l10 30" fill="none" stroke="#FBBF24" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" />
                                <rect data-a="rise" data-at="0.2,0.4" x="162" y="252" width="76" height="13" rx="6" fill="#67BCD4" />
                                <rect data-a="rise" data-at="0.25,0.45" x="170" y="271" width="60" height="11" rx="5" fill="#4FA9C4" />
                                @foreach ([['Hook', 42, 300, -8, 0.6], ['Audience', 146, 320, 3, 0.68], ['Goal', 278, 298, 7, 0.76]] as [$word, $x, $y, $tilt, $at])
                                    <g data-a="rise" data-at="{{ $at }},{{ $at + 0.14 }}">
                                        <g transform="rotate({{ $tilt }} {{ $x + 40 }} {{ $y + 20 }})">
                                            <rect x="{{ $x }}" y="{{ $y }}" width="{{ $word === 'Audience' ? 108 : 80 }}" height="40" rx="6" fill="#ABDAE7" />
                                            <text x="{{ $x + ($word === 'Audience' ? 54 : 40) }}" y="{{ $y + 26 }}" text-anchor="middle" font-family="Poppins, sans-serif" font-weight="700" font-size="16" fill="#132A38">{{ $word }}</text>
                                        </g>
                                    </g>
                                @endforeach
                                @foreach ([[80, 90, 0.55], [322, 70, 0.6], [330, 230, 0.66], [64, 220, 0.7]] as [$x, $y, $at])
                                    <path data-a="pop" data-at="{{ $at }},{{ $at + 0.12 }}" d="M{{ $x }} {{ $y - 10 }} L{{ $x + 3 }} {{ $y - 3 }} L{{ $x + 10 }} {{ $y }} L{{ $x + 3 }} {{ $y + 3 }} L{{ $x }} {{ $y + 10 }} L{{ $x - 3 }} {{ $y + 3 }} L{{ $x - 10 }} {{ $y }} L{{ $x - 3 }} {{ $y - 3 }} Z" fill="#FFFFFF" />
                                @endforeach
                            </g>

                            {{-- 02 · Script: a pen writes it, line by line; then it is approved. --}}
                            <g data-pr-art="1" opacity="0">
                                <g data-a="rise" data-at="0,0.18">
                                    <rect x="78" y="40" width="244" height="322" rx="14" fill="#FFFFFF" />
                                    <path d="M290 40 h32 v32 z" fill="#E4F2F7" />
                                </g>
                                <text data-a="fade" data-at="0.1,0.22" x="110" y="88" font-family="Poppins, sans-serif" font-weight="800" font-size="16" fill="#132A38" letter-spacing="1">SCENE 01 — THE HOOK</text>
                                @foreach ([[128, 170], [162, 150], [196, 175], [230, 120], [264, 165], [298, 140]] as $l => [$y, $length])
                                    <path data-a="draw" data-pen="1" data-at="{{ 0.2 + $l * 0.1 }},{{ 0.3 + $l * 0.1 }}" pathLength="1" d="{{ $scrawl($y, $length, $l) }}"
                                          fill="none" stroke="#284250" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" />
                                @endforeach
                                <g data-a="pen">
                                    <path d="M0 0 L5 -15 L48 -58 L58 -48 L15 -5 Z" fill="#67BCD4" />
                                    <path d="M48 -58 L54 -64 a7 7 0 0 1 10 10 L58 -48 Z" fill="#132A38" />
                                    <path d="M0 0 L5 -15 L15 -5 Z" fill="#132A38" />
                                </g>
                                <g data-a="pop" data-at="0.84,0.98">
                                    <g transform="rotate(-12 236 334)">
                                        <rect x="176" y="314" width="120" height="40" rx="6" fill="none" stroke="#10B981" stroke-width="4" />
                                        <text x="236" y="341" text-anchor="middle" font-family="Poppins, sans-serif" font-weight="800" font-size="18" fill="#10B981" letter-spacing="2">APPROVED</text>
                                    </g>
                                </g>
                            </g>

                            {{-- 03 · Shoot: framed up, the camera assembles, REC, and the clapper snaps. --}}
                            <g data-pr-art="2" opacity="0">
                                @foreach (['M40 90 V56 H74', 'M326 56 H360 V90', 'M360 310 V344 H326', 'M74 344 H40 V310'] as $corner)
                                    <path data-a="draw" data-at="0,0.22" pathLength="1" d="{{ $corner }}" fill="none" stroke="#ABDAE7" stroke-width="5" stroke-linecap="round" />
                                @endforeach
                                <g data-a="rise" data-at="0.1,0.32">
                                    <rect x="104" y="122" width="156" height="104" rx="16" fill="#284250" stroke="#67BCD4" stroke-width="4" />
                                    <path d="M260 148 L304 126 V222 L260 200 Z" fill="#284250" stroke="#67BCD4" stroke-width="4" stroke-linejoin="round" />
                                </g>
                                <g data-a="pop" data-at="0.25,0.42">
                                    <circle cx="182" cy="174" r="38" fill="#132A38" stroke="#ABDAE7" stroke-width="6" />
                                    <circle cx="182" cy="174" r="16" fill="#67BCD4" />
                                    <circle cx="174" cy="166" r="5" fill="#FFFFFF" opacity="0.8" />
                                </g>
                                <circle data-a="pop" data-at="0.42,0.5" class="pr-blink" cx="128" cy="142" r="7" fill="#F87171" />
                                <text data-a="fade" data-at="0.44,0.52" x="141" y="147" font-family="Poppins, sans-serif" font-weight="700" font-size="13" fill="#FFFFFF">REC</text>
                                <g data-a="rise" data-at="0.4,0.55">
                                    <rect x="70" y="262" width="150" height="76" rx="6" fill="#132A38" stroke="#FFFFFF" stroke-width="4" />
                                    <text x="84" y="292" font-family="Poppins, sans-serif" font-weight="700" font-size="14" fill="#FFFFFF">SC 01</text>
                                    <text x="84" y="320" font-family="Poppins, sans-serif" font-weight="700" font-size="14" fill="#ABDAE7">TAKE 3</text>
                                    <g data-a="clap" data-at="0.6,0.74" style="transform-origin: 70px 262px">
                                        <rect x="70" y="240" width="150" height="20" rx="4" fill="#FFFFFF" />
                                        @for ($s = 0; $s < 5; $s++)
                                            <path d="M{{ 82 + $s * 28 }} 240 l14 0 l-10 20 l-14 0 z" fill="#132A38" />
                                        @endfor
                                    </g>
                                </g>
                                <rect data-a="flash" data-at="0.72,0.86" x="0" y="0" width="400" height="400" fill="#FFFFFF" />
                            </g>

                            {{-- 04 · Edit: clips land on the timeline, the playhead runs. --}}
                            <g data-pr-art="3" opacity="0">
                                <g data-a="rise" data-at="0,0.18">
                                    <rect x="70" y="36" width="260" height="146" rx="12" fill="#284250" />
                                    <path d="M188 88 L218 109 L188 130 Z" fill="#ABDAE7" />
                                </g>
                                <rect data-a="rise" data-at="0.06,0.2" x="36" y="202" width="328" height="160" rx="14" fill="#0D1F2A" />
                                @foreach ([['V1', 240], ['V2', 280], ['A1', 324]] as [$track, $y])
                                    <text data-a="fade" data-at="0.15,0.25" x="48" y="{{ $y + 5 }}" font-family="Poppins, sans-serif" font-weight="700" font-size="12" fill="#ABDAE7">{{ $track }}</text>
                                @endforeach
                                @foreach ([[78, 226, 92, '#67BCD4', 0.2], [174, 226, 72, '#8ACCE0', 0.28], [250, 226, 100, '#4FA9C4', 0.36], [120, 266, 64, '#A78BFA', 0.42], [212, 266, 58, '#A78BFA', 0.48]] as [$x, $y, $w, $fill, $at])
                                    <rect data-a="slide" data-at="{{ $at }},{{ $at + 0.12 }}" x="{{ $x }}" y="{{ $y }}" width="{{ $w }}" height="28" rx="6" fill="{{ $fill }}" />
                                @endforeach
                                @for ($b = 0; $b < 24; $b++)
                                    @php $h = [10, 18, 26, 14, 30, 22, 12, 28, 34, 16, 24, 20, 32, 12, 26, 18, 30, 14, 22, 28, 16, 24, 12, 20][$b]; @endphp
                                    <rect data-a="grow" data-at="{{ 0.42 + $b * 0.008 }},{{ 0.52 + $b * 0.008 }}" x="{{ 80 + $b * 11 }}" y="{{ 324 - $h / 2 }}" width="6" height="{{ $h }}" rx="3" fill="#ABDAE7" />
                                @endfor
                                <g data-a="move" data-dx="250" data-at="0.58,0.98">
                                    <line x1="80" y1="212" x2="80" y2="352" stroke="#F87171" stroke-width="3" />
                                    <path d="M72 206 h16 l-8 10 z" fill="#F87171" />
                                </g>
                            </g>

                            {{-- 05 · Review: your notes appear on the cut, then the sign-off. --}}
                            <g data-pr-art="4" opacity="0">
                                <g data-a="rise" data-at="0,0.18">
                                    <rect x="56" y="44" width="288" height="164" rx="14" fill="#284250" />
                                    <rect x="74" y="186" width="252" height="5" rx="2.5" fill="#FFFFFF" opacity="0.2" />
                                    <rect x="74" y="186" width="96" height="5" rx="2.5" fill="#67BCD4" />
                                    <circle cx="170" cy="188.5" r="7" fill="#FFFFFF" />
                                    <path d="M186 98 L216 118 L186 138 Z" fill="#ABDAE7" opacity="0.6" />
                                </g>
                                <g data-a="pop" data-at="0.2,0.36">
                                    <rect x="34" y="230" width="210" height="50" rx="16" fill="#FFFFFF" />
                                    <circle cx="60" cy="255" r="13" fill="#67BCD4" />
                                    <text x="82" y="261" font-family="Poppins, sans-serif" font-weight="600" font-size="15" fill="#132A38">Love the hook!</text>
                                </g>
                                <g data-a="pop" data-at="0.36,0.52">
                                    <rect x="150" y="292" width="216" height="50" rx="16" fill="#ABDAE7" />
                                    <circle cx="176" cy="317" r="13" fill="#132A38" />
                                    <text x="198" y="323" font-family="Poppins, sans-serif" font-weight="600" font-size="15" fill="#132A38">Tighter at 0:12?</text>
                                </g>
                                <g data-a="pop" data-at="0.52,0.64">
                                    <rect x="60" y="300" width="74" height="30" rx="15" fill="#34D399" />
                                    <text x="97" y="320" text-anchor="middle" font-family="Poppins, sans-serif" font-weight="700" font-size="13" fill="#0D1F2A">Done</text>
                                </g>
                                <circle data-a="pop" data-at="0.68,0.82" cx="300" cy="96" r="36" fill="#10B981" />
                                <path data-a="draw" data-at="0.78,0.94" pathLength="1" d="M283 97 l12 12 l22 -24" fill="none" stroke="#FFFFFF" stroke-width="8" stroke-linecap="round" stroke-linejoin="round" />
                            </g>

                            {{-- 06 · Publish: up it goes, posted, and the hearts come in. --}}
                            <g data-pr-art="5" opacity="0">
                                <g data-a="rise" data-at="0,0.18">
                                    <rect x="122" y="26" width="156" height="310" rx="26" fill="#0D1F2A" stroke="#ABDAE7" stroke-width="4" />
                                    <rect x="176" y="38" width="48" height="8" rx="4" fill="#ABDAE7" opacity="0.5" />
                                </g>
                                <g data-a="fade" data-at="0.12,0.28">
                                    <rect x="138" y="62" width="124" height="158" rx="10" fill="url(#pr-post)" />
                                    <path d="M188 124 L218 141 L188 158 Z" fill="#FFFFFF" />
                                </g>
                                <rect x="138" y="234" width="124" height="8" rx="4" fill="#FFFFFF" opacity="0.15" />
                                <rect data-a="scalex" data-at="0.2,0.55" x="138" y="234" width="124" height="8" rx="4" fill="#67BCD4" />
                                <g data-a="pop" data-at="0.55,0.65">
                                    <text x="192" y="272" text-anchor="middle" font-family="Poppins, sans-serif" font-weight="800" font-size="17" fill="#34D399">Posted</text>
                                    <path d="M228 265 l5 5 l9 -10" fill="none" stroke="#34D399" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" />
                                </g>
                                <g data-a="fade" data-at="0.6,0.7">
                                    <path d="{{ $heart }}" transform="translate(148 290) scale(0.8)" fill="#F87171" />
                                    <circle cx="200" cy="300" r="8" fill="none" stroke="#ABDAE7" stroke-width="3" />
                                    <path d="M236 292 l16 8 l-16 8 z" fill="#ABDAE7" />
                                </g>
                                @foreach ([[296, 250, 0.6], [318, 220, 0.67], [286, 200, 0.74], [330, 270, 0.8], [306, 180, 0.86]] as [$x, $y, $at])
                                    <g data-a="float" data-at="{{ $at }},{{ min(1, $at + 0.3) }}"><path d="{{ $heart }}" transform="translate({{ $x }} {{ $y }})" fill="#F87171" /></g>
                                @endforeach
                                <g data-a="pop" data-at="0.66,0.78">
                                    <rect x="70" y="350" width="260" height="36" rx="18" fill="#FFFFFF" />
                                    <text x="200" y="374" text-anchor="middle" font-family="Poppins, sans-serif" font-weight="700" font-size="14" fill="#132A38">Scheduled · Instagram · YouTube</text>
                                </g>
                            </g>
                        </svg>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
