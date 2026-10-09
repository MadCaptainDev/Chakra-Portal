{{--
    The stage every "how it works" walkthrough plays on: a 9:16 frame with a
    step header, a phone, a caption and a finger, plus the cover and recap
    cards and the controls under it. Driven by resources/js/guide-player.js;
    the guide's own script ($entry) draws the phone's screen.

    @param string $entry   the guide's Vite entry
    @param string $file    download name for "Save as video"
    @param array  $menu    sidebar links: [label, svg path, data attribute or null]
    @param array  $cover   [title, text, svg path]
    @param array  $end     [title, items[], note]
    @param array  $open    [url, label] -- the real screen
--}}
@push('styles')
    <style>
            .eg { --b9:#132A38; --b8:#284250; --b4:#67BCD4; --b3:#8ACCE0; --b2:#ABDAE7; --amber:#fbbf24; --sky:#38bdf8; --em:#10b981; --red:#fca5a5; font-family: Poppins, ui-sans-serif, system-ui, sans-serif; }
            .eg-wrap { display:flex; flex-direction:column; align-items:center; gap:14px; }
            .eg-fit { width:min(100%, calc((100dvh - 210px) * 9 / 16)); min-width:300px; }
            .eg-stage { position:relative; width:405px; height:720px; transform-origin:top left; overflow:hidden; border-radius:22px;
                background: radial-gradient(120% 70% at 50% 0%, #1d4458 0%, #132A38 55%, #0c1d27 100%); color:#fff; box-shadow:0 20px 60px -20px rgba(0,0,0,.6); }
            .eg-top { position:absolute; inset:0 0 auto 0; height:74px; padding:16px 20px 0; display:flex; align-items:center; gap:12px; }
            .eg-stepno { flex:none; width:40px; height:40px; border-radius:12px; background:var(--b4); color:var(--b9); font-weight:800; font-size:18px; display:flex; align-items:center; justify-content:center; }
            .eg-steptitle { font-weight:700; font-size:18px; line-height:1.15; }
            .eg-stepsub { font-size:11px; letter-spacing:.14em; text-transform:uppercase; color:var(--b3); font-weight:600; }
            .eg-phone { position:absolute; left:32px; top:84px; width:341px; height:500px; border-radius:26px; background:var(--b9); border:6px solid #0a1720;
                box-shadow: 0 0 0 1px rgba(255,255,255,.08), 0 18px 40px -10px rgba(0,0,0,.7); overflow:hidden; }
            .eg-cap { position:absolute; left:20px; right:20px; bottom:22px; min-height:104px; border-radius:18px; background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.1);
                padding:14px 16px; font-size:16px; line-height:1.42; font-weight:500; display:flex; align-items:center; }
            .eg-cap b { color:var(--amber); font-weight:700; }
            .eg-cap.swap { animation: egSwap .45s ease; }
            @keyframes egSwap { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:none; } }

            /* ——— the phone's screen ——— */
            .eg-bar { height:40px; display:flex; align-items:center; gap:10px; padding:0 12px; background:#0f2330; border-bottom:1px solid rgba(255,255,255,.06); font-size:13px; font-weight:700; }
            .eg-burger { width:18px; height:12px; border-top:2px solid #cfe7ef; border-bottom:2px solid #cfe7ef; position:relative; }
            .eg-burger::after { content:''; position:absolute; left:0; right:0; top:3px; border-top:2px solid #cfe7ef; }
            .eg-screen { position:relative; height:calc(100% - 40px); padding:10px 11px; overflow:hidden; }
            /* sidebar scene */
            .eg-side { position:absolute; inset:40px 30% 0 0; z-index:6; background:#0f2330; border-right:1px solid rgba(255,255,255,.08); padding:12px 8px; transform:translateX(-102%); transition: transform .45s cubic-bezier(.2,.8,.2,1); }
            .eg-side.open { transform:none; }
            .eg-side .sec { font-size:8.5px; letter-spacing:.14em; text-transform:uppercase; color:rgba(228,242,247,.45); font-weight:700; padding:2px 8px 6px; }
            .eg-side .lnk { display:flex; align-items:center; gap:8px; padding:8px; border-radius:9px; font-size:11.5px; color:rgba(228,242,247,.75); }
            .eg-side .lnk.on { background:rgba(103,188,212,.18); color:#fff; font-weight:700; }
            .eg-side svg { width:16px; height:16px; }
            .eg-shade { position:absolute; inset:40px 0 0 0; z-index:5; background:rgba(0,0,0,.45); opacity:0; transition: opacity .3s; pointer-events:none; }
            .eg-shade.open { opacity:1; }
            .eg-toast { position:absolute; left:50%; bottom:14px; z-index:7; transform:translateX(-50%); border-radius:99px; background:var(--em); color:#fff; font-size:11px; font-weight:700; padding:6px 13px; white-space:nowrap; animation: egIn .3s ease; }

            /* the finger */
            .eg-finger { position:absolute; z-index:20; left:0; top:0; width:30px; height:30px; margin:-15px 0 0 -15px; border-radius:50%;
                background:rgba(255,255,255,.35); border:2.5px solid #fff; box-shadow:0 4px 14px rgba(0,0,0,.45); transition: transform .65s cubic-bezier(.45,.05,.25,1), opacity .3s; pointer-events:none; }
            .eg-finger.press { animation: egPress .35s ease; }
            @keyframes egPress { 50% { scale:.72; background:rgba(255,255,255,.7); } }
            .eg-ripple { position:absolute; z-index:19; width:16px; height:16px; margin:-8px 0 0 -8px; border-radius:50%; border:2px solid #fff; pointer-events:none; animation: egRipple .6s ease-out forwards; }
            @keyframes egRipple { to { transform:scale(4); opacity:0; } }
            .eg-ring { box-shadow:0 0 0 2px var(--amber), 0 0 18px rgba(251,191,36,.5) !important; transition: box-shadow .3s; }

            /* title & end cards */
            .eg-card-full { position:absolute; inset:0; z-index:30; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; padding:40px 34px;
                background: radial-gradient(110% 70% at 50% 30%, #1d4458 0%, #132A38 60%, #0c1d27 100%); transition: opacity .5s; }
            .eg-card-full.hide { opacity:0; pointer-events:none; }
            .eg-card-full img { width:120px; margin-bottom:30px; opacity:.95; }
            .eg-card-full .ico { width:74px; height:74px; border-radius:22px; background:rgba(103,188,212,.18); color:var(--b2); display:flex; align-items:center; justify-content:center; margin-bottom:22px; }
            .eg-card-full h2 { font-size:34px; font-weight:800; line-height:1.08; letter-spacing:-.02em; }
            .eg-card-full p { margin-top:14px; font-size:16px; color:rgba(228,242,247,.75); line-height:1.45; }
            .eg-card-full ol { margin-top:22px; text-align:left; font-size:15px; line-height:1.4; display:flex; flex-direction:column; gap:10px; color:rgba(228,242,247,.9); }
            .eg-card-full ol li { display:flex; gap:10px; align-items:flex-start; }
            .eg-card-full ol li b { flex:none; width:24px; height:24px; border-radius:7px; background:var(--b4); color:var(--b9); font-size:13px; display:flex; align-items:center; justify-content:center; }

            .eg-progress { position:absolute; left:20px; right:20px; top:6px; height:3px; border-radius:3px; background:rgba(255,255,255,.12); overflow:hidden; z-index:40; }
            .eg-progress i { display:block; height:100%; width:0; background:var(--b4); transition: width .3s linear; }

            /* controls (outside the stage, never recorded) */
            .eg-controls { display:flex; flex-wrap:wrap; align-items:center; justify-content:center; gap:8px; }
            .eg-cbtn { min-height:44px; border-radius:12px; padding:0 16px; font-size:14px; font-weight:700; background:rgba(255,255,255,.08); color:#fff; border:1px solid rgba(255,255,255,.12); display:inline-flex; align-items:center; gap:8px; }
            .eg-cbtn.primary { background:var(--b4); color:var(--b9); border-color:transparent; }
            .eg-cbtn:disabled { opacity:.4; }
            .eg-chapters { display:flex; flex-wrap:wrap; justify-content:center; gap:6px; max-width:560px; }
            .eg-chap { border-radius:99px; padding:5px 11px; font-size:12px; font-weight:600; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); color:rgba(228,242,247,.7); }
            .eg-chap.on { background:rgba(103,188,212,.2); color:#fff; border-color:rgba(103,188,212,.5); }
            .eg-note { font-size:12px; color:rgba(228,242,247,.5); text-align:center; max-width:520px; }
            @keyframes egIn { from { opacity:0; transform:translateY(6px) scale(.98); } to { opacity:1; transform:none; } }
    </style>
@endpush
@push('scripts')
    @vite($entry)
@endpush

<div class="eg eg-wrap" data-guide data-file="{{ $file }}">
    <div class="eg-fit" data-fit>
        <div class="eg-stage" data-stage>
            <div class="eg-progress"><i data-progress></i></div>
            <div class="eg-top">
                <div class="eg-stepno" data-stepno>1</div>
                <div>
                    <div class="eg-stepsub" data-stepsub></div>
                    <div class="eg-steptitle" data-steptitle></div>
                </div>
            </div>

            <div class="eg-phone" data-phone>
                <div class="eg-bar"><span class="eg-burger"></span> Chakra Portal</div>
                <div class="eg-screen" data-screen></div>
                <div class="eg-shade" data-shade></div>
                <div class="eg-side" data-side>
                    <div class="sec">My work</div>
                    @foreach ($menu as [$label, $path, $attr])
                        <div class="lnk" @if ($attr) {{ $attr }} @endif><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $path }}"/></svg>{{ $label }}</div>
                    @endforeach
                </div>
            </div>

            <div class="eg-cap" data-cap></div>
            <div class="eg-finger" data-finger style="opacity:0"></div>

            <div class="eg-card-full" data-cover>
                <img src="{{ asset('images/chakra-logo.png') }}" alt="Chakra">
                <div class="ico"><svg width="40" height="40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $cover[2] }}"/></svg></div>
                <h2>{{ $cover[0] }}</h2>
                <p>{{ $cover[1] }}</p>
            </div>
            <div class="eg-card-full hide" data-end>
                <img src="{{ asset('images/chakra-logo.png') }}" alt="Chakra">
                <h2>{{ $end[0] }}</h2>
                <ol>
                    @foreach ($end[1] as $i => $item)
                        <li><b>{{ $i + 1 }}</b><span>{!! $item !!}</span></li>
                    @endforeach
                </ol>
                <p>{{ $end[2] }}</p>
            </div>
        </div>
    </div>

    <div class="eg-controls">
        <button type="button" class="eg-cbtn primary" data-play>▶ Play</button>
        <button type="button" class="eg-cbtn" data-restart>↺ Restart</button>
        <button type="button" class="eg-cbtn" data-record title="Records this walkthrough into a video file you can send on WhatsApp">⬇ Save as video</button>
        <a href="{{ $open[0] }}" class="eg-cbtn">{{ $open[1] }} →</a>
    </div>
    <div class="eg-chapters" data-chapters></div>
    <p class="eg-note" data-note>Save as video works in Chrome on a computer: when it asks what to share, choose <strong>this tab</strong>. The video downloads when the walkthrough ends.</p>
</div>
