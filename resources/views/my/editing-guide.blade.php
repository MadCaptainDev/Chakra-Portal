{{--
    "How My Editing works": a self-playing walkthrough for editors on the
    shared guide stage (guides/_player). The phone shows a mock of
    my/editing.blade.php drawn by resources/js/editing-guide.js -- it never
    touches anyone's board.
--}}
@php
    $film = 'M4 3.5h16a1 1 0 011 1v9a1 1 0 01-1 1H4a1 1 0 01-1-1v-9a1 1 0 011-1z M10 6.75v4.5L13.75 9 10 6.75z M3 19h7.5m3 0H21 M12 16.5v5';
@endphp
<x-app-layout title="How My Editing works">
    @push('styles')
        <style>
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
        </style>
    @endpush

    @include('guides._player', [
        'entry' => 'resources/js/editing-guide.js',
        'file' => 'My-Editing-how-to',
        'menu' => [
            ['Dashboard', 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6', null],
            ['My Editing', $film, 'data-side-editing'],
            ['My Shoots', 'M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z M15 13a3 3 0 11-6 0 3 3 0 016 0z', null],
            ['My To-dos', 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z', null],
            ['My Timesheet', 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z', null],
        ],
        'cover' => ['My Editing', 'Your videos for the day — add them, work on them, fix corrections and mark them posted. In about a minute.', $film],
        'end' => ['That’s it.', [
            'Add — tap the box, pick from <em>Up next</em>, or type.',
            '▶ Start working when you open the project.',
            'Corrections go on the video. Tick them off.',
            '✓ Done, pick Reel / Post / Story, then Mark posted.',
        ], 'Tomorrow’s videos are already in the planner — edit ahead.'],
        'open' => [route('my.editing'), 'Open My Editing'],
    ])
</x-app-layout>
