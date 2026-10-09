{{--
    "How the Comment & Message Checker works": a self-playing walkthrough on
    the shared guide stage (guides/_player). The phone shows a mock of
    inbox-desk/index.blade.php drawn by resources/js/inbox-guide.js -- it
    never ticks a real check.
--}}
@php
    $bubble = 'M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z M8.5 11.5l2.5 2.5 4.5-4.5';
@endphp
<x-app-layout title="How the checker works">
    @push('styles')
        <style>
            .ic-h1 { font-size:15px; font-weight:800; letter-spacing:-.01em; line-height:1.15; }
            .ic-eyebrow { font-size:8.5px; letter-spacing:.16em; text-transform:uppercase; color:var(--b3); font-weight:600; }
            .ic-sum { margin-top:8px; display:flex; align-items:center; gap:12px; border-radius:14px; padding:10px 12px; border:1px solid rgba(255,255,255,.1);
                background: linear-gradient(135deg, rgba(103,188,212,.16), rgba(255,255,255,.05)); transition: background .5s, border-color .5s; }
            .ic-sum.clear { background:rgba(16,185,129,.12); border-color:rgba(16,185,129,.3); }
            .ic-ring { position:relative; flex:none; width:64px; height:64px; }
            .ic-ring svg { width:100%; height:100%; transform:rotate(-90deg); }
            .ic-ring circle { fill:none; stroke-width:9; }
            .ic-ring .bg { stroke:rgba(255,255,255,.1); }
            .ic-ring .fg { stroke:var(--b4); stroke-linecap:round; transition: stroke-dashoffset .7s ease, stroke .5s; }
            .ic-sum.clear .fg { stroke:var(--em); }
            .ic-ring .num { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:16px; }
            .ic-ring .num small { font-size:11px; color:rgba(228,242,247,.45); }
            .ic-big { font-size:15px; font-weight:700; line-height:1.2; }
            .ic-small { font-size:10px; color:rgba(228,242,247,.65); margin-top:2px; }
            .ic-small .late { color:#fcd34d; font-weight:700; }
            .ic-live { margin-top:5px; font-size:9px; color:rgba(228,242,247,.5); display:flex; align-items:center; gap:4px; }
            .ic-live i { width:6px; height:6px; border-radius:50%; background:var(--em); }
            .ic-list { margin-top:8px; display:flex; flex-direction:column; gap:7px; }
            .ic-acc { border-radius:14px; padding:9px; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); transition: background .4s, border-color .4s; }
            .ic-acc.clear { background:rgba(16,185,129,.04); border-color:rgba(16,185,129,.18); }
            .ic-acc.late { border-color:rgba(251,191,36,.35); }
            .ic-who { display:flex; align-items:center; gap:8px; }
            .ic-av { position:relative; flex:none; width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:800;
                background: linear-gradient(135deg, #d946ef, #f43f5e 55%, #fbbf24); box-shadow:0 0 0 2px rgba(255,255,255,.1); }
            .ic-acc.clear .ic-av { box-shadow:0 0 0 2px rgba(16,185,129,.7); }
            .ic-av b { position:absolute; right:-3px; bottom:-3px; width:14px; height:14px; border-radius:50%; background:var(--em); color:var(--b9); font-size:8px; display:flex; align-items:center; justify-content:center; box-shadow:0 0 0 2px var(--b9); }
            .ic-hn { font-size:11.5px; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
            .ic-cn { font-size:9.5px; color:rgba(228,242,247,.5); }
            .ic-pill { flex:none; border-radius:99px; padding:2px 7px; font-size:9px; font-weight:700; background:rgba(255,255,255,.1); color:rgba(228,242,247,.75); }
            .ic-pill.done { background:rgba(16,185,129,.15); color:#6ee7b7; } .ic-pill.late { background:rgba(251,191,36,.15); color:#fcd34d; }
            .ic-ig { flex:none; width:26px; height:26px; border-radius:50%; display:flex; align-items:center; justify-content:center; color:rgba(228,242,247,.6); }
            .ic-tiles { margin-top:7px; display:grid; grid-template-columns:1fr 1fr; gap:6px; }
            .ic-tile { min-height:54px; border-radius:11px; padding:7px 8px; background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.1); transition: background .3s, border-color .3s, box-shadow .3s; }
            .ic-tile.done { background:rgba(16,185,129,.1); border-color:rgba(16,185,129,.3); }
            .ic-tile.open-panel { box-shadow:0 0 0 2px var(--b3); }
            .ic-tile.flash { box-shadow:0 0 0 2px #6ee7b7, 0 0 20px rgba(52,211,153,.35); }
            .ic-tt { display:flex; justify-content:space-between; align-items:flex-start; font-size:11px; font-weight:700; }
            .ic-tile.done .ic-tt { color:#a7f3d0; }
            .ic-mark { flex:none; width:16px; height:16px; border-radius:50%; box-shadow: inset 0 0 0 2px rgba(255,255,255,.2); display:flex; align-items:center; justify-content:center; font-size:9px; }
            .ic-tile.done .ic-mark { background:var(--em); color:var(--b9); box-shadow:none; }
            .ic-ts { margin-top:4px; font-size:9.5px; color:rgba(228,242,247,.5); line-height:1.3; }
            .ic-ts.hint { color:var(--b3); font-weight:700; }
            .ic-tile.done .ic-ts { color:rgba(209,250,229,.75); }
            .ic-tag { display:inline-block; margin-bottom:2px; border-radius:5px; padding:1px 4px; font-size:8.5px; font-weight:700; background:rgba(251,191,36,.15); color:#fcd34d; }
            .ic-panel { margin-top:6px; border-radius:11px; padding:9px; background:rgba(19,42,56,.7); border:1px solid rgba(255,255,255,.1); animation: egIn .25s ease; }
            .ic-prow { display:flex; align-items:center; justify-content:space-between; gap:8px; }
            .ic-pl { font-size:11.5px; font-weight:700; }
            .ic-ps { font-size:9px; color:rgba(228,242,247,.5); }
            .ic-step { display:flex; align-items:center; border-radius:99px; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); }
            .ic-step span { width:30px; height:30px; display:flex; align-items:center; justify-content:center; font-size:15px; color:rgba(255,255,255,.8); }
            .ic-step b { width:26px; text-align:center; font-size:14px; }
            .ic-btns { margin-top:7px; display:grid; grid-template-columns:1fr 1fr; gap:5px; }
            .ic-b { height:32px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:10.5px; font-weight:700; background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.15); }
            .ic-b.go { background:var(--b4); color:var(--b9); border-color:transparent; font-weight:800; }
            .ic-by { display:flex; align-items:center; justify-content:space-between; font-size:10.5px; color:rgba(228,242,247,.8); }
            .ic-by u { text-decoration:none; color:var(--b3); font-weight:700; padding:4px 6px; }
            .ic-all { margin-top:5px; height:28px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:9.5px; font-weight:600; color:rgba(228,242,247,.6); }
            .ic-feed { margin-top:8px; border-radius:12px; padding:7px 9px; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); }
            .ic-feed .lbl { font-size:8.5px; letter-spacing:.14em; text-transform:uppercase; color:rgba(228,242,247,.55); font-weight:700; margin-bottom:4px; }
            .ic-fi { display:flex; align-items:center; gap:6px; padding:3px 0; font-size:10px; color:rgba(228,242,247,.8); animation: egIn .3s ease; }
            .ic-fi i { flex:none; width:18px; height:18px; border-radius:50%; background:rgba(16,185,129,.15); color:#6ee7b7; font-style:normal; font-size:9px; font-weight:800; display:flex; align-items:center; justify-content:center; }
            .ic-fi span:last-child { margin-left:auto; color:rgba(228,242,247,.5); }
            .ic-scroll { transition: transform .6s ease; }
        </style>
    @endpush

    @include('guides._player', [
        'entry' => 'resources/js/inbox-guide.js',
        'file' => 'Comment-Message-Checker-how-to',
        'menu' => [
            ['Dashboard', 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6', null],
            ['My Editing', 'M4 3.5h16a1 1 0 011 1v9a1 1 0 01-1 1H4a1 1 0 01-1-1v-9a1 1 0 011-1z M10 6.75v4.5L13.75 9 10 6.75z M3 19h7.5m3 0H21 M12 16.5v5', null],
            ['My To-dos', 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z', null],
            ['Comment & Message Checker', $bubble, 'data-side-checker'],
            ['My Timesheet', 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z', null],
        ],
        'cover' => ['Comment & Message Checker', 'Every client’s Instagram DMs and comments, answered every day — and everyone can see it is done. In about a minute.', $bubble],
        'end' => ['That’s it.', [
            'Open the checker. Each account has a <b>DMs</b> and a <b>Comments</b> tile.',
            'Reply on Instagram — the camera icon opens the account.',
            'Tap the tile, set how many you replied to, tap <b>Done</b>.',
            'Nothing new? <b>Nothing new</b>, or mark the whole account.',
        ], 'Missed a day? It waits, marked late. One Done clears it.'],
        'open' => [route('inbox-desk.index'), 'Open the checker'],
    ])
</x-app-layout>
