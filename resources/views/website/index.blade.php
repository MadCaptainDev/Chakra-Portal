<x-app-layout title="Website">
    <x-slot name="header">
        <x-page-header title="Website" subtitle="Who visits chakragroups.in, what they look at, and who messages you from it" />
    </x-slot>

    @php
        $tab = request('tab') === 'team' ? 'team' : 'traffic';
        $srcLabel = ['instagram' => 'Instagram', 'google' => 'Google', 'whatsapp' => 'WhatsApp', 'facebook' => 'Facebook', 'direct' => 'Typed / saved link', 'search' => 'Other search', 'youtube' => 'YouTube', 'linkedin' => 'LinkedIn', 'other' => 'Other websites'];
        $srcIcon = ['instagram' => '📸', 'google' => '🔎', 'whatsapp' => '💬', 'facebook' => '👍', 'direct' => '⌨️', 'search' => '🔎', 'youtube' => '▶️', 'linkedin' => '💼', 'other' => '🔗'];
        $waLabel = ['like_this' => 'I loved your … reel', 'start' => 'I want reels for my business', 'price' => 'Packages & pricing', 'shoot' => 'Book a shoot', 'own' => 'Their own message', 'link' => 'WhatsApp link on the page'];
        $bar = fn ($n, $max) => $max ? max(2, round($n / $max * 100)) : 0;
    @endphp

    <div class="space-y-6">
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('website.index', ['days' => $days]) }}" class="rounded-full px-4 py-2 text-sm font-semibold {{ $tab === 'traffic' ? 'bg-brand-400 text-brand-900' : 'bg-white/5 text-brand-100/70 hover:text-white' }}">Traffic</a>
            <a href="{{ route('website.index', ['tab' => 'team']) }}" class="rounded-full px-4 py-2 text-sm font-semibold {{ $tab === 'team' ? 'bg-brand-400 text-brand-900' : 'bg-white/5 text-brand-100/70 hover:text-white' }}">Team page</a>
            @if ($tab === 'traffic')
                <div class="ml-auto flex rounded-full bg-white/5 p-1">
                    @foreach ([1 => 'Today', 7 => '7 days', 30 => '30 days', 90 => '90 days'] as $d => $l)
                        <a href="{{ route('website.index', ['days' => $d]) }}" class="rounded-full px-3 py-1.5 text-xs font-semibold {{ $days === $d ? 'bg-white text-brand-900' : 'text-brand-100/70 hover:text-white' }}">{{ $l }}</a>
                    @endforeach
                </div>
            @endif
        </div>

        @if (session('status'))
            <div class="rounded-xl bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200">{{ session('status') }}</div>
        @endif

        @if ($tab === 'traffic')
            @if (! $since)
                <x-card class="p-6">
                    <p class="font-semibold text-white">Counting has just started.</p>
                    <p class="mt-1 text-sm text-brand-100/70">The website had no visitor counting before today. Numbers appear here as people visit — check back tomorrow. Your own visits (and anyone signed in to the portal) are not counted.</p>
                </x-card>
            @else
                <p class="text-xs text-brand-100/50">Counting since {{ \Illuminate\Support\Carbon::parse($since)->format('j M Y, g:i A') }}. Staff and bots are left out.</p>

                {{-- The headline numbers --}}
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
                    @foreach ([
                        ['Visitors', $totals['visitors'], $totals['new'].' first time'],
                        ['Visits', $totals['sessions'], $totals['views'].' pages seen'],
                        ['Tapped WhatsApp', $totals['whatsapp'], round($totals['whatsapp'] / $sessionCount * 100).'% of visits'],
                        ['WhatsApp chats started', $totals['chats'], 'messages that arrived "via chakragroups.in"'],
                        ['Pages per visit', $totals['sessions'] ? number_format($totals['views'] / $totals['sessions'], 1) : '0', 'how far they browse'],
                    ] as [$label, $value, $note])
                        <x-card class="p-4">
                            <p class="text-xs uppercase tracking-wide text-brand-100/60">{{ $label }}</p>
                            <p class="mt-1 text-2xl font-bold text-white sm:text-3xl">{{ is_numeric($value) ? number_format((float) $value) : $value }}</p>
                            <p class="mt-1 text-[11px] text-brand-100/50">{{ $note }}</p>
                        </x-card>
                    @endforeach
                </div>

                {{-- Visitors a day --}}
                @if ($days > 1)
                    @php $peak = max(1, collect($daily)->max('visitors')); @endphp
                    <x-card class="p-4 sm:p-6">
                        <h3 class="font-semibold text-white">Visitors a day</h3>
                        <p class="mb-4 text-xs text-brand-100/60">Green: of them, how many tapped WhatsApp.</p>
                        <div class="overflow-x-auto">
                            <div class="flex h-40 min-w-max items-end gap-1">
                                @foreach ($daily as $d)
                                    <div class="flex h-full w-5 flex-col items-center justify-end" title="{{ $d['date']->format('D j M') }}: {{ $d['visitors'] }} visitors, {{ $d['whatsapp'] }} tapped WhatsApp">
                                        <span class="mb-1 text-[9px] text-brand-100/60">{{ $d['visitors'] ?: '' }}</span>
                                        <div class="relative w-full rounded-t bg-brand-400/70" style="height: {{ $bar($d['visitors'], $peak) }}%">
                                            @if ($d['whatsapp'])
                                                <div class="absolute inset-x-0 bottom-0 rounded-t bg-emerald-400" style="height: {{ $bar($d['whatsapp'], max(1, $d['visitors'])) }}%"></div>
                                            @endif
                                        </div>
                                        <span class="mt-1 text-[9px] text-brand-100/50">{{ $d['date']->format($days > 30 ? 'j' : 'j M') }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </x-card>
                @endif

                <div class="grid gap-4 lg:grid-cols-2">
                    {{-- Where they come from --}}
                    <x-card class="p-4 sm:p-6">
                        <h3 class="font-semibold text-white">Where they come from</h3>
                        <p class="mb-3 text-xs text-brand-100/60">How each visit started. Add <code class="text-brand-200">?utm_source=instagram&utm_campaign=bio</code> to links you share to see each one separately.</p>
                        @php $max = max(1, $sources->max()); @endphp
                        @forelse ($sources as $src => $n)
                            <div class="mb-2">
                                <div class="flex justify-between text-sm"><span class="text-white">{{ $srcIcon[$src] ?? '🔗' }} {{ $srcLabel[$src] ?? ucfirst($src) }}</span><span class="font-semibold text-white">{{ $n }} <span class="text-xs font-normal text-brand-100/50">{{ round($n / $sessionCount * 100) }}%</span></span></div>
                                <div class="mt-1 h-2 rounded-full bg-white/5"><div class="h-2 rounded-full bg-brand-400" style="width: {{ $bar($n, $max) }}%"></div></div>
                            </div>
                        @empty
                            <p class="text-sm text-brand-100/50">Nothing yet.</p>
                        @endforelse
                        @if ($referrers->isNotEmpty())
                            <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-brand-100/50">Websites linking to you</p>
                            <p class="mt-1 text-sm text-brand-100/80">{{ $referrers->map(fn ($n, $h) => $h.' ('.$n.')')->implode(' · ') }}</p>
                        @endif
                        @if ($campaigns->isNotEmpty())
                            <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-brand-100/50">Tagged links</p>
                            <p class="mt-1 text-sm text-brand-100/80">{{ $campaigns->map(fn ($n, $c) => $c.' ('.$n.')')->implode(' · ') }}</p>
                        @endif
                    </x-card>

                    {{-- What made them message --}}
                    <x-card class="p-4 sm:p-6">
                        <h3 class="font-semibold text-white">What made them message</h3>
                        <p class="mb-3 text-xs text-brand-100/60">The message they picked in the WhatsApp box, and the page they were on.</p>
                        @forelse ($waChoices as $k => $n)
                            <div class="flex justify-between border-b border-white/5 py-1.5 text-sm"><span class="text-white">💬 {{ $waLabel[$k] ?? $k }}</span><span class="font-semibold text-white">{{ $n }}</span></div>
                        @empty
                            <p class="text-sm text-brand-100/50">No WhatsApp taps yet.</p>
                        @endforelse
                        @if ($waLiked->isNotEmpty())
                            <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-emerald-300">Reels that made them ask for “one like this”</p>
                            @foreach ($waLiked as $t => $n)
                                <p class="mt-1 text-sm text-white">{{ $t }} <span class="text-brand-100/50">· {{ $n }}</span></p>
                            @endforeach
                        @endif
                        @if ($waFrom->isNotEmpty())
                            <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-brand-100/50">Tapped from</p>
                            <p class="mt-1 text-sm text-brand-100/80">{{ $waFrom->map(fn ($n, $p) => ($p === '/' ? 'Homepage' : $p).' ('.$n.')')->implode(' · ') }}</p>
                        @endif
                    </x-card>
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    {{-- What attracts them on the homepage --}}
                    <x-card class="p-4 sm:p-6">
                        <h3 class="font-semibold text-white">How far into the homepage they get</h3>
                        <p class="mb-3 text-xs text-brand-100/60">Of {{ $homeSessions }} homepage visits, the share that reached each part.</p>
                        @forelse ($sections as $id => $s)
                            <div class="mb-2">
                                <div class="flex justify-between text-sm"><span class="text-white">{{ $s['label'] }}</span><span class="font-semibold text-white">{{ $s['pct'] }}%</span></div>
                                <div class="mt-1 h-2 rounded-full bg-white/5"><div class="h-2 rounded-full bg-amber-300" style="width: {{ $s['pct'] }}%"></div></div>
                            </div>
                        @empty
                            <p class="text-sm text-brand-100/50">Nothing yet.</p>
                        @endforelse
                        @if ($scroll->isNotEmpty())
                            <p class="mt-3 text-xs text-brand-100/60">Read half a page: {{ $scroll['50'] ?? 0 }} visits · read to the end: {{ $scroll['90'] ?? 0 }}</p>
                        @endif
                    </x-card>

                    {{-- The reels that pull them in --}}
                    <x-card class="p-4 sm:p-6">
                        <h3 class="font-semibold text-white">Reels people watch on the site</h3>
                        <p class="mb-3 text-xs text-brand-100/60">Played, or case study opened, by how many people.</p>
                        @forelse ($reels as $r)
                            <div class="flex items-center justify-between gap-3 border-b border-white/5 py-1.5 text-sm">
                                <span class="min-w-0 truncate text-white">{{ $r->label ?: 'Piece #'.$r->target }}</span>
                                <span class="shrink-0 text-xs text-brand-100/60"><span class="font-semibold text-white">{{ $r->people }}</span> people · {{ $r->plays }} plays · {{ $r->opens }} case studies</span>
                            </div>
                        @empty
                            <p class="text-sm text-brand-100/50">Nothing yet.</p>
                        @endforelse
                        @if ($categories->isNotEmpty())
                            <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-brand-100/50">Categories picked</p>
                            <p class="mt-1 text-sm text-brand-100/80">{{ $categories->map(fn ($n, $c) => $c.' ('.$n.')')->implode(' · ') }}</p>
                        @endif
                    </x-card>
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    <x-card class="p-4 sm:p-6">
                        <h3 class="font-semibold text-white">Pages</h3>
                        @foreach ($pages as $p => $n)
                            <div class="flex justify-between border-b border-white/5 py-1.5 text-sm"><span class="truncate text-white">{{ $p === '/' ? 'Homepage' : $p }}</span><span class="font-semibold text-white">{{ $n }}</span></div>
                        @endforeach
                    </x-card>
                    <x-card class="p-4 sm:p-6">
                        <h3 class="font-semibold text-white">On what</h3>
                        @foreach ($devices as $d => $n)
                            <div class="flex justify-between border-b border-white/5 py-1.5 text-sm"><span class="text-white">{{ ['phone' => '📱 Phone', 'computer' => '💻 Computer', 'tablet' => '📲 Tablet'][$d] ?? $d }}</span><span class="font-semibold text-white">{{ $n }} <span class="text-xs font-normal text-brand-100/50">{{ round($n / $sessionCount * 100) }}%</span></span></div>
                        @endforeach
                    </x-card>
                </div>
            @endif
        @else
            {{-- ——— The Team page's people ——— --}}
            <x-card class="p-4 sm:p-6">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h3 class="font-semibold text-white">People on the Team page</h3>
                        <p class="text-xs text-brand-100/60">Only people switched <span class="font-semibold text-emerald-300">On</span> are shown at <a href="{{ route('team') }}" target="_blank" class="underline">chakragroups.in/team</a> and in the homepage's team section.</p>
                    </div>
                    <span class="text-xs text-brand-100/60">{{ $team->where('is_visible', true)->count() }} of {{ $team->count() }} shown</span>
                </div>
            </x-card>

            <div class="grid gap-3 md:grid-cols-2">
                @foreach ($team as $m)
                    <x-card class="p-4" x-data="{ edit: false }">
                        <div class="flex items-start gap-3">
                            @if ($m->photoUrl())
                                <img src="{{ $m->photoUrl() }}" alt="" class="h-16 w-16 shrink-0 rounded-xl object-cover">
                            @else
                                <span class="flex h-16 w-16 shrink-0 items-center justify-center rounded-xl bg-white/5 text-xl font-bold text-brand-300/60">{{ mb_substr($m->name, 0, 1) }}</span>
                            @endif
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-white">{{ $m->name }}</p>
                                <p class="text-sm text-brand-300">{{ $m->role ?: 'No role' }}</p>
                                @if (! $m->photoUrl())<p class="text-[11px] text-amber-200">No photo yet</p>@endif
                                @if ($m->bio)<p class="mt-1 line-clamp-2 text-xs text-brand-100/60">{{ $m->bio }}</p>@endif
                            </div>
                            <form method="POST" action="{{ route('website.team.toggle', $m) }}">
                                @csrf
                                <button class="rounded-full px-3 py-1.5 text-xs font-bold {{ $m->is_visible ? 'bg-emerald-400 text-brand-900' : 'bg-white/10 text-brand-100/70' }}">{{ $m->is_visible ? 'On' : 'Off' }}</button>
                            </form>
                        </div>
                        <div class="mt-3 flex items-center gap-3 text-xs">
                            <button type="button" @click="edit = ! edit" class="font-semibold text-brand-300 hover:text-white">Edit</button>
                            <form method="POST" action="{{ route('website.team.move', $m) }}" class="flex gap-1">
                                @csrf
                                <button name="to" value="up" class="rounded px-2 py-1 text-brand-100/60 hover:bg-white/10" aria-label="Move up">↑</button>
                                <button name="to" value="down" class="rounded px-2 py-1 text-brand-100/60 hover:bg-white/10" aria-label="Move down">↓</button>
                            </form>
                            <form method="POST" action="{{ route('website.team.destroy', $m) }}" class="ml-auto" onsubmit="return confirm('Remove {{ addslashes($m->name) }} from the Team page?')">
                                @csrf @method('DELETE')
                                <button class="text-red-300/70 hover:text-red-200">Remove</button>
                            </form>
                        </div>
                        <form x-show="edit" x-cloak method="POST" action="{{ route('website.team.update', $m) }}" enctype="multipart/form-data" class="mt-3 space-y-2 border-t border-white/10 pt-3">
                            @csrf @method('PUT')
                            <x-text-input name="name" value="{{ $m->name }}" class="block w-full" required />
                            <x-text-input name="role" value="{{ $m->role }}" placeholder="Role, e.g. Editor" class="block w-full" />
                            <textarea name="bio" rows="2" maxlength="500" placeholder="One or two lines about them (optional)" class="block w-full rounded-md border-white/20 bg-white/10 text-sm text-white">{{ $m->bio }}</textarea>
                            <label class="block text-xs text-brand-100/70">Photo <input type="file" name="photo" accept="image/*" class="mt-1 block w-full text-xs text-brand-100/70"></label>
                            <x-primary-button>Save</x-primary-button>
                        </form>
                    </x-card>
                @endforeach
            </div>

            <x-card class="p-4 sm:p-6">
                <h3 class="mb-3 font-semibold text-white">Add someone</h3>
                <form method="POST" action="{{ route('website.team.store') }}" enctype="multipart/form-data" class="grid gap-2 sm:grid-cols-2">
                    @csrf
                    <x-text-input name="name" placeholder="Name" required />
                    <x-text-input name="role" placeholder="Role" />
                    <textarea name="bio" rows="2" maxlength="500" placeholder="One or two lines (optional)" class="rounded-md border-white/20 bg-white/10 text-sm text-white sm:col-span-2"></textarea>
                    <label class="text-xs text-brand-100/70">Photo <input type="file" name="photo" accept="image/*" class="mt-1 block w-full text-xs"></label>
                    <div class="flex items-end justify-end"><x-primary-button>Add (hidden)</x-primary-button></div>
                </form>
            </x-card>
        @endif
    </div>
</x-app-layout>
