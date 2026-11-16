{{-- One reel's script and where people like and share it (ReelAnalysis). --}}
@php
    $avg = (int) ($reel->account?->avg_views_30d ?? 0);
    $tags = $reel->friendTags();
@endphp
@if ($analysis->transcript !== null)
    <div class="grid grid-cols-3 gap-2 text-center">
        <div class="rounded-lg bg-white/5 p-2">
            <p class="text-sm font-bold text-white">{{ $avg ? round($reel->play_count / max(1, $avg), 1).'×' : '—' }}</p>
            <p class="text-[10px] text-brand-100/50">views vs average</p>
        </div>
        <div class="rounded-lg bg-white/5 p-2">
            <p class="text-sm font-bold text-white">{{ $reel->likeRate() ?? '—' }}</p>
            <p class="text-[10px] text-brand-100/50">likes / 100 views</p>
        </div>
        <div class="rounded-lg bg-white/5 p-2" title="Latest comments that tag someone — people sending it on. Instagram shows nobody the real share count.">
            <p class="text-sm font-bold text-white">{{ $tags }} / {{ count($reel->comments ?? []) }}</p>
            <p class="text-[10px] text-brand-100/50">comments tag a friend</p>
        </div>
    </div>

    @if ($analysis->hook || $analysis->format)
        <div class="text-sm text-brand-100/80">
            @if ($analysis->format)<p><span class="text-[11px] font-semibold uppercase tracking-wider text-brand-100/50">Format</span> · {{ $analysis->format }}@if ($analysis->topic) · {{ $analysis->topic }}@endif</p>@endif
            @if ($analysis->hook)<p class="mt-1"><span class="text-[11px] font-semibold uppercase tracking-wider text-brand-100/50">Hook</span> · {{ $analysis->hook }}</p>@endif
        </div>
    @endif

    @foreach (['like_moments' => ['❤️ Where people like it', 'text-rose-200'], 'share_moments' => ['↗️ Where people share it', 'text-sky-200']] as $field => [$label, $tone])
        @if (! empty($analysis->{$field}))
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-wider {{ $tone }}">{{ $label }}</p>
                <ul class="mt-1 space-y-1.5">
                    @foreach ($analysis->{$field} as $m)
                        <li class="text-sm text-brand-100/80"><span class="font-mono text-xs text-amber-200">{{ $m['at'] }}</span> {{ $m['what'] }} <span class="text-brand-100/55">— {{ $m['why'] }}</span></li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endforeach

    @if (! empty($analysis->comment_read['themes']) || ! empty($analysis->comment_read['quoted_lines']))
        <div class="text-sm text-brand-100/80">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-100/50">What the comments say</p>
            @if (! empty($analysis->comment_read['themes']))<p class="mt-1">{{ implode(' · ', $analysis->comment_read['themes']) }}</p>@endif
            @if (! empty($analysis->comment_read['quoted_lines']))<p class="mt-1 text-brand-100/60">Lines people repeat: “{{ implode('” · “', $analysis->comment_read['quoted_lines']) }}”</p>@endif
        </div>
    @endif

    <details class="rounded-lg bg-brand-900/40 p-3">
        <summary class="cursor-pointer text-xs font-semibold text-brand-200">📜 Full script ({{ count($analysis->transcript) }} lines{{ $analysis->language ? ', '.$analysis->language : '' }})</summary>
        <div class="mt-2 space-y-2">
            @foreach ($analysis->transcript as $line)
                <div class="text-sm">
                    <span class="font-mono text-xs text-amber-200">{{ $line['at'] }}</span>
                    <span class="text-white">{{ $line['said'] }}</span>
                    @if ($line['english'] && $line['english'] !== $line['said'])<p class="text-brand-100/60">{{ $line['english'] }}</p>@endif
                    @if ($line['on_screen'])<p class="text-[11px] text-brand-100/45">On screen: {{ $line['on_screen'] }}</p>@endif
                </div>
            @endforeach
        </div>
    </details>
@endif
