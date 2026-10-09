{{--
    "How My Editing works": a self-playing walkthrough for editors, shaped
    like a phone video (9:16) so it reads on a phone and can be saved as a
    video file (resources/js/editing-guide.js records the tab in Chrome).

    The phone on screen is a mock of my/editing.blade.php, not the real
    page -- the guide never touches anyone's board. Styles are plain CSS
    under .eg so the animation does not depend on Tailwind's purge.
--}}
<x-app-layout title="How My Editing works">
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
            .eg-head { display:flex; align-items:flex-end; justify-content:space-between; }
            .eg-date { font-size:8.5px; letter-spacing:.16em; text-transform:uppercase; color:var(--b3); font-weight:600; }
            .eg-h1 { font-size:19px; font-weight:800; letter-spacing:-.01em; }
            .eg-icons { display:flex; gap:4px; color:rgba(228,242,247,.45); }
            .eg-ico { width:26px; height:26px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:13px; }
            .eg-ico.hot { background:rgba(103,188,212,.18); color:var(--b2); }
            .eg-add { margin-top:9px; position:relative; border-radius:14px; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); padding:7px; }
            .eg-row { display:flex; gap:6px; }
            .eg-input { flex:1; height:36px; border-radius:10px; background:rgba(255,255,255,.1); display:flex; align-items:center; padding:0 10px; font-size:12.5px; color:#fff; white-space:nowrap; overflow:hidden; }
            .eg-input.focus { box-shadow:0 0 0 2px var(--b4); }
            .eg-input .ph { color:rgba(228,242,247,.4); }
            .eg-caret { display:inline-block; width:1.5px; height:15px; background:#fff; margin-left:1px; animation: egBlink 1s steps(1) infinite; }
            @keyframes egBlink { 50% { opacity:0; } }
            .eg-btn { height:36px; border-radius:10px; padding:0 13px; font-size:12px; font-weight:800; display:flex; align-items:center; background:var(--b4); color:var(--b9); }
            .eg-btn.off { opacity:.4; }
            .eg-chips { display:flex; gap:5px; margin-top:6px; }
            .eg-chip { border-radius:99px; padding:3px 9px; font-size:10px; font-weight:600; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); color:rgba(228,242,247,.8); }
            .eg-drop { position:absolute; left:0; right:0; top:calc(100% + 5px); z-index:5; border-radius:14px; background:#0f2330; border:1px solid rgba(255,255,255,.15); box-shadow:0 18px 30px -8px rgba(0,0,0,.6); padding:4px 0; }
            .eg-drop .lbl { font-size:8.5px; letter-spacing:.14em; text-transform:uppercase; color:var(--b3); font-weight:700; padding:5px 11px 3px; }
            .eg-opt { display:flex; align-items:center; gap:8px; padding:6px 11px; }
            .eg-opt.hi { background:rgba(255,255,255,.1); }
            .eg-badge { flex:none; width:24px; height:24px; border-radius:7px; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:800; }
            .eg-badge.p { background:rgba(103,188,212,.2); color:var(--b2); } .eg-badge.c { background:rgba(251,191,36,.15); color:#fde68a; } .eg-badge.t { background:rgba(255,255,255,.1); color:rgba(228,242,247,.7); }
            .eg-ot { font-size:11.5px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
            .eg-os { font-size:9.5px; color:rgba(228,242,247,.55); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
            .eg-os .late { color:var(--red); font-weight:700; }
            .eg-tabs { margin-top:9px; display:grid; grid-template-columns:repeat(4,1fr); gap:3px; padding:3px; border-radius:14px; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); }
            .eg-tab { height:38px; border-radius:11px; display:flex; flex-direction:column; align-items:center; justify-content:center; font-size:10px; font-weight:600; color:rgba(228,242,247,.6); transition: background .25s; }
            .eg-tab.on { background:rgba(255,255,255,.15); color:#fff; }
            .eg-tab .n { margin-top:1px; border-radius:99px; padding:0 6px; font-size:9.5px; }
            .n.todo { background:rgba(255,255,255,.1); } .n.working { background:rgba(251,191,36,.15); color:#fde68a; } .n.done { background:rgba(56,189,248,.15); color:#bae6fd; } .n.posted { background:rgba(16,185,129,.15); color:#a7f3d0; }
            .eg-tab .n.bump { animation: egBump .5s ease; }
            @keyframes egBump { 40% { transform:scale(1.45); } }
            .eg-lane { margin-top:8px; display:flex; flex-direction:column; gap:7px; }
            .eg-card { border-radius:12px; background:rgba(19,42,56,.85); border:1px solid rgba(255,255,255,.1); padding:9px 10px; animation: egIn .35s ease; }
            @keyframes egIn { from { opacity:0; transform:translateY(6px) scale(.98); } to { opacity:1; transform:none; } }
            .eg-ct { font-size:12.5px; font-weight:700; line-height:1.25; }
            .eg-cs { font-size:9.5px; color:rgba(228,242,247,.55); margin-top:1px; }
            .eg-fixes { margin-top:6px; border-left:2px solid rgba(251,191,36,.45); padding-left:7px; display:flex; flex-direction:column; gap:3px; }
            .eg-fix { display:flex; align-items:center; gap:6px; font-size:10.5px; color:#fef3c7; }
            .eg-box { flex:none; width:14px; height:14px; border-radius:4px; border:1.5px solid rgba(255,255,255,.3); display:flex; align-items:center; justify-content:center; font-size:9px; }
            .eg-fix.ok { color:rgba(228,242,247,.4); text-decoration:line-through; } .eg-fix.ok .eg-box { background:var(--em); border-color:var(--em); color:#fff; }
            .eg-acts { margin-top:8px; display:flex; gap:5px; flex-wrap:wrap; }
            .eg-a { height:30px; border-radius:8px; padding:0 10px; display:flex; align-items:center; font-size:10.5px; font-weight:800; }
            .eg-a.start { flex:1; justify-content:center; background:var(--amber); color:var(--b9); }
            .eg-a.done { flex:1; justify-content:center; background:var(--sky); color:var(--b9); }
            .eg-a.done.off { opacity:.4; }
            .eg-a.fix { background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); color:#fde68a; font-weight:600; }
            .eg-where { margin-top:8px; border-radius:9px; background:rgba(255,255,255,.05); padding:6px; }
            .eg-kinds { display:grid; grid-template-columns:repeat(4,1fr); gap:3px; }
            .eg-k { height:26px; border-radius:6px; font-size:9.5px; font-weight:600; display:flex; align-items:center; justify-content:center; background:rgba(255,255,255,.05); color:rgba(228,242,247,.7); }
            .eg-k.on { background:var(--b4); color:var(--b9); }
            .eg-post { margin-top:5px; display:flex; gap:4px; }
            .eg-post .eg-input { height:30px; font-size:10.5px; }
            .eg-a.posted { background:var(--em); color:#fff; }
            .eg-ok { margin-top:7px; font-size:11px; font-weight:700; color:#6ee7b7; display:flex; justify-content:space-between; }
            .eg-ok span:last-child { color:var(--b3); font-weight:600; }
            .eg-empty { text-align:center; font-size:10.5px; color:rgba(228,242,247,.35); padding:22px 6px; }
            .eg-timer { color:#fde68a; font-weight:600; }
            .eg-fixin { margin-top:6px; display:flex; gap:4px; }
            .eg-fixin .eg-input { height:30px; font-size:10.5px; }
            .eg-fixin .eg-input.focus { box-shadow:0 0 0 2px var(--amber); }

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
        </style>
    @endpush
    @push('scripts')
        @vite('resources/js/editing-guide.js')
    @endpush

    <div class="eg eg-wrap" data-editing-guide data-logo="{{ asset('images/chakra-logo.png') }}" data-back="{{ route('my.editing') }}">
        <div class="eg-fit" data-fit>
            <div class="eg-stage" data-stage>
                <div class="eg-progress"><i data-progress></i></div>
                <div class="eg-top">
                    <div class="eg-stepno" data-stepno>1</div>
                    <div>
                        <div class="eg-stepsub" data-stepsub>My Editing</div>
                        <div class="eg-steptitle" data-steptitle>Open My Editing</div>
                    </div>
                </div>

                <div class="eg-phone" data-phone>
                    <div class="eg-bar"><span class="eg-burger"></span> Chakra Portal</div>
                    <div class="eg-screen" data-screen></div>
                    <div class="eg-shade" data-shade></div>
                    <div class="eg-side" data-side>
                        <div class="sec">My work</div>
                        <div class="lnk"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>Dashboard</div>
                        <div class="lnk" data-side-editing><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M4 3.5h16a1 1 0 011 1v9a1 1 0 01-1 1H4a1 1 0 01-1-1v-9a1 1 0 011-1z M10 6.75v4.5L13.75 9 10 6.75z M3 19h7.5m3 0H21 M12 16.5v5"/></svg>My Editing</div>
                        <div class="lnk"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>My Shoots</div>
                        <div class="lnk"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>My To-dos</div>
                        <div class="lnk"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>My Timesheet</div>
                    </div>
                </div>

                <div class="eg-cap" data-cap></div>
                <div class="eg-finger" data-finger style="opacity:0"></div>

                <div class="eg-card-full" data-cover>
                    <img src="{{ asset('images/chakra-logo.png') }}" alt="Chakra">
                    <div class="ico"><svg width="40" height="40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M4 3.5h16a1 1 0 011 1v9a1 1 0 01-1 1H4a1 1 0 01-1-1v-9a1 1 0 011-1z M10 6.75v4.5L13.75 9 10 6.75z M3 19h7.5m3 0H21 M12 16.5v5"/></svg></div>
                    <h2>My Editing</h2>
                    <p>Your videos for the day — add them, work on them, fix corrections and mark them posted. In about a minute.</p>
                </div>
                <div class="eg-card-full hide" data-end>
                    <img src="{{ asset('images/chakra-logo.png') }}" alt="Chakra">
                    <h2>That’s it.</h2>
                    <ol>
                        <li><b>1</b><span>Add — tap the box, pick from <em>Up next</em>, or type.</span></li>
                        <li><b>2</b><span>▶ Start working when you open the project.</span></li>
                        <li><b>3</b><span>Corrections go on the video. Tick them off.</span></li>
                        <li><b>4</b><span>✓ Done, pick Reel / Post / Story, then Mark posted.</span></li>
                    </ol>
                    <p>Tomorrow’s videos are already in the planner — edit ahead.</p>
                </div>
            </div>
        </div>

        <div class="eg-controls">
            <button type="button" class="eg-cbtn primary" data-play>▶ Play</button>
            <button type="button" class="eg-cbtn" data-restart>↺ Restart</button>
            <button type="button" class="eg-cbtn" data-record title="Records this walkthrough into a video file you can send on WhatsApp">⬇ Save as video</button>
            <a href="{{ route('my.editing') }}" class="eg-cbtn">Open My Editing →</a>
        </div>
        <div class="eg-chapters" data-chapters></div>
        <p class="eg-note" data-note>Save as video works in Chrome on a computer: when it asks what to share, choose <strong>this tab</strong>. The video downloads when the walkthrough ends.</p>
    </div>
</x-app-layout>
