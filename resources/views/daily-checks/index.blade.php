{{-- The end-of-day checks, as the teams sent them. See DailyCheckController. --}}
<x-app-layout title="EOD checks">
    <x-slot name="header">
        <x-page-header title="EOD checks" eyebrow="Team"
                       subtitle="Each team's end-of-day checklist, as sent — the last 30 days." />
    </x-slot>

    <div class="mx-auto max-w-3xl space-y-6">
        @if ($days->isEmpty())
            <div class="rounded-xl border border-dashed border-white/15 px-6 py-12 text-center">
                <p class="text-sm text-brand-100/70">No end-of-day checks yet.</p>
                <p class="mt-1 text-xs text-brand-100/50">They appear here when a team sends its "EOD CHECK" message to the portal's WhatsApp number.</p>
            </div>
        @endif

        @foreach ($days as $date => $checks)
            <section>
                <h2 class="mb-2 text-xs font-semibold uppercase tracking-[0.16em] text-brand-300">
                    {{ \Illuminate\Support\Carbon::parse($date)->isToday() ? 'Today' : \Illuminate\Support\Carbon::parse($date)->format('l j F') }}
                </h2>
                <div class="space-y-3">
                    @foreach ($checks as $check)
                        @php
                            $missed = $check->missed();
                            $ok = count($check->items ?? []) - count($missed);
                        @endphp
                        <x-card class="p-4" x-data="{ raw: false }">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="font-semibold text-white">{{ $check->team }}</p>
                                    <p class="text-xs text-brand-100/55">
                                        {{ $ok }} of {{ count($check->items ?? []) }} done
                                        @if ($check->reportedBy) · sent by {{ $check->reportedBy->name }} @endif
                                        · {{ $check->updated_at->format('g:i A') }}
                                    </p>
                                </div>
                                <span @class([
                                    'shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold',
                                    'bg-amber-400/15 text-amber-200' => $missed !== [],
                                    'bg-emerald-400/15 text-emerald-200' => $missed === [],
                                ])>{{ $missed !== [] ? ($check->status ?? 'Pending') : 'All done' }}</span>
                            </div>

                            <ul class="mt-3 space-y-1.5 text-sm">
                                @foreach ($check->items ?? [] as $item)
                                    <li class="flex items-start gap-2.5">
                                        <span class="mt-0.5 shrink-0">{{ $item['ok'] ? '✅' : '❌' }}</span>
                                        <span class="{{ $item['ok'] ? 'text-brand-100/85' : 'font-semibold text-red-200' }}">
                                            {{ $item['label'] }}
                                            @if ($item['note'])<span class="font-normal text-brand-100/55"> — {{ $item['note'] }}</span>@endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>

                            <button type="button" @click="raw = !raw" class="mt-3 text-xs text-brand-100/45 underline underline-offset-2 hover:text-white"
                                    x-text="raw ? 'Hide the message' : 'Show the message as sent'"></button>
                            <pre x-show="raw" x-cloak class="mt-2 whitespace-pre-wrap rounded-lg bg-black/20 p-3 font-sans text-xs text-brand-100/70">{{ $check->message }}</pre>
                        </x-card>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
</x-app-layout>
