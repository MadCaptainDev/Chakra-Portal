{{-- Videos waiting for a verdict, oldest first, and the ones decided lately. --}}
@php
    $verdictTone = ['good_to_go' => 'bg-emerald-400/15 text-emerald-200', 'minor_fixes' => 'bg-amber-400/15 text-amber-200', 'needs_fixes' => 'bg-red-400/15 text-red-200'];
    // By day: waiting by the day it was uploaded, decided by the day it was decided.
    $day = fn ($at) => $at->isToday() ? 'Today' : ($at->isYesterday() ? 'Yesterday' : $at->format($at->isCurrentYear() ? 'l, j M' : 'j M Y'));
    $waitingByDay = $waiting->groupBy(fn ($v) => $v->created_at->toDateString())->sortKeysDesc();
    $doneByDay = $done->groupBy(fn ($v) => ($v->reviewed_at ?? $v->updated_at)->toDateString())->sortKeysDesc();
@endphp
<x-app-layout title="Video review" dark>
    <div class="mx-auto max-w-6xl space-y-8 pb-16">
        <div>
            <h1 class="text-[28px] font-extrabold tracking-tight text-white">Video review</h1>
            <p class="text-sm text-brand-100/60">{{ $waiting->count() }} waiting · editors upload from My Editing, Gemini checks each one, you decide.</p>
        </div>

        {{-- Who must approve (admins) --}}
        @if (auth()->user()->isAdmin())
            @php $chosen = array_map('intval', $settings->approver_ids ?? []); @endphp
            <details class="rounded-2xl bg-[#15303f] p-4 ring-1 ring-white/[0.07]" @if (! $chosen) open @endif>
                <summary class="flex cursor-pointer list-none flex-wrap items-center gap-2">
                    <span class="text-sm font-semibold text-white">Who must approve</span>
                    <span class="text-xs text-brand-100/55">
                        {{ $settings->approvers()->pluck('name')->implode(', ') ?: 'Anyone who reviews (one OK is enough)' }}
                        · {{ $settings->require_for_ready ? 'Video ready waits for it' : 'Video ready does not wait' }}
                    </span>
                    <span class="ml-auto text-xs text-brand-300">Change</span>
                </summary>
                <form method="POST" action="{{ route('reviews.settings') }}" class="mt-4 space-y-4">
                    @csrf @method('PUT')
                    <div>
                        <p class="text-xs text-brand-100/60">Every person ticked has to approve a cut before it is approved. Leave all unticked and the first OK from any reviewer is enough.</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($staff as $person)
                                <label class="cursor-pointer">
                                    <input type="checkbox" name="approver_ids[]" value="{{ $person->id }}" class="peer sr-only" @checked(in_array($person->id, $chosen, true))>
                                    <span class="inline-flex h-10 items-center rounded-full px-4 text-sm ring-1 ring-white/15 transition peer-checked:bg-emerald-400 peer-checked:font-semibold peer-checked:text-brand-900 peer-checked:ring-emerald-400 text-brand-100/80">{{ $person->name }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <label class="flex items-center gap-2.5 text-sm text-white">
                        <input type="hidden" name="require_for_ready" value="0">
                        <input type="checkbox" name="require_for_ready" value="1" @checked($settings->require_for_ready) class="h-5 w-5 rounded border-white/30 bg-transparent text-emerald-400 focus:ring-emerald-400">
                        A video can only be made Video ready once its cut is approved
                    </label>
                    <x-btn type="submit">Save</x-btn>
                </form>
            </details>
        @endif

        <section>
            <h2 class="mb-3 text-[17px] font-semibold text-white">Waiting for you</h2>
            @forelse ($waitingByDay as $date => $videos)
                <p class="mb-2 mt-4 flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.14em] text-brand-100/55 first:mt-0">
                    {{ $day(\Illuminate\Support\Carbon::parse($date)) }} <span class="rounded-full bg-white/10 px-2 py-0.5 text-[11px] normal-case tracking-normal text-brand-100/70">{{ $videos->count() }}</span>
                    <span class="h-px flex-1 bg-white/10"></span>
                </p>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($videos as $v)
                        @include('reviews._tile', ['v' => $v])
                    @endforeach
                </div>
            @empty
                <p class="rounded-2xl border border-emerald-400/25 bg-emerald-400/[0.06] px-5 py-6 text-sm text-emerald-100/85">Nothing waiting. New uploads appear here and on the bell.</p>
            @endforelse
        </section>

        @if ($done->isNotEmpty())
            <section>
                <h2 class="mb-3 text-[17px] font-semibold text-white">Decided lately</h2>
                @foreach ($doneByDay as $date => $videos)
                    <p class="mb-2 mt-4 flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.14em] text-brand-100/55 first:mt-0">
                        {{ $day(\Illuminate\Support\Carbon::parse($date)) }} <span class="rounded-full bg-white/10 px-2 py-0.5 text-[11px] normal-case tracking-normal text-brand-100/70">{{ $videos->count() }}</span>
                        <span class="h-px flex-1 bg-white/10"></span>
                    </p>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($videos as $v)
                            @include('reviews._tile', ['v' => $v])
                        @endforeach
                    </div>
                @endforeach
            </section>
        @endif
    </div>
</x-app-layout>
