{{-- The competitor brain: what it believes, how it learned it, and a box to ask it. CompetitorBrainController. --}}
@php
    use App\Models\CompetitorBrainNote;
    $events = [
        'scripted' => ['📜', 'text-brand-200'], 'learned' => ['💡', 'text-emerald-300'], 'confirmed' => ['✓', 'text-emerald-200'],
        'contradicted' => ['↯', 'text-amber-200'], 'retired' => ['✕', 'text-rose-300'], 'asked' => ['❓', 'text-sky-200'], 'failed' => ['!', 'text-rose-300'],
    ];
    $total = $notes->flatten()->count();
@endphp
<x-app-layout title="Competitor brain">
    <x-slot name="header">
        <x-page-header title="Competitor brain" eyebrow="Competitors"
                       subtitle="Everything we have learned from competitors' reels: scripts, hooks, and where people like and share. It learns from every reel it studies and corrects itself.">
            <x-slot name="actions">
                <x-btn :href="route('competitors.index')" variant="secondary">All competitors</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mx-auto max-w-6xl space-y-4">
        {{-- Scope + state --}}
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <a href="{{ route('competitors.brain') }}" class="rounded-full px-3 py-1.5 ring-1 {{ ! $account ? 'bg-white text-brand-900 ring-white' : 'text-brand-100/70 ring-white/15 hover:text-white' }}">All competitors</a>
            @foreach ($accounts as $a)
                <a href="{{ route('competitors.brain', ['account' => $a->id]) }}" class="rounded-full px-3 py-1.5 ring-1 {{ $account?->id === $a->id ? 'bg-white text-brand-900 ring-white' : 'text-brand-100/70 ring-white/15 hover:text-white' }}">{{ '@'.$a->username }}</a>
            @endforeach
            <span class="ml-auto text-xs text-brand-100/55">{{ $studied }} {{ Str::plural('reel', $studied) }} studied · {{ $total }} {{ Str::plural('note', $total) }}</span>
        </div>

        @if ($pending > 0)
            <div x-data x-init="setTimeout(() => location.reload(), 20000)" class="rounded-xl bg-violet-400/10 p-3 text-sm text-violet-200 ring-1 ring-violet-400/30">
                🧠 Learning — {{ $pending }} {{ Str::plural('step', $pending) }} left{{ $busy ? ', working on one now' : '' }}. This page refreshes itself and keeps it going.
            </div>
        @endif

        {{-- Ask --}}
        <x-card padding="md">
            <form method="POST" action="{{ route('competitors.brain.ask') }}" class="flex flex-col gap-2 sm:flex-row">
                @csrf
                @if ($account)<input type="hidden" name="account" value="{{ $account->id }}">@endif
                <x-text-input name="question" class="flex-1" :value="old('question')" required maxlength="1000"
                              placeholder="Ask the brain — e.g. What hooks make dusky-skin beauty reels go viral? Write me 3 hooks for a pet clinic." />
                <x-btn type="submit">Ask</x-btn>
            </form>
            @if ($answer)
                <div class="mt-4 rounded-xl bg-brand-900/40 p-4">
                    <p class="text-xs text-brand-100/50">❓ {{ $answer['question'] }}</p>
                    <div class="ai-md mt-2">{!! \App\Support\AiMarkdown::html($answer['answer']) !!}</div>
                </div>
            @endif
        </x-card>

        <div class="grid gap-4 lg:grid-cols-[1fr_380px]">
            {{-- What it believes --}}
            <div class="space-y-4">
                @forelse (CompetitorBrainNote::CATEGORIES as $key => $label)
                    @continue(! isset($notes[$key]))
                    <x-card padding="md">
                        <x-section-heading :title="$label" :subtitle="$notes[$key]->count().' '.Str::plural('note', $notes[$key]->count())" />
                        <ul class="space-y-2.5">
                            @foreach ($notes[$key] as $n)
                                <li class="flex items-start gap-3">
                                    <span class="mt-0.5 shrink-0 rounded-md bg-white/10 px-1.5 py-0.5 text-[11px] font-bold text-white" title="Reels backing it">{{ $n->strength }}</span>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm text-brand-100/85">{{ $n->insight }}</p>
                                        <p class="mt-0.5 text-[11px] text-brand-100/45">{{ $n->competitor_account_id ? '@'.$n->account?->username : 'All competitors' }} · {{ count($n->evidence ?? []) }} {{ Str::plural('reel', count($n->evidence ?? [])) }} · last seen {{ $n->last_confirmed_at?->diffForHumans() }}</p>
                                    </div>
                                    @can('competitors.create')
                                        <form method="POST" action="{{ route('competitors.brain.retire', $n) }}" onsubmit="return confirm('Retire this note? The brain stops using it.')">
                                            @csrf
                                            <button class="text-[11px] text-brand-100/40 hover:text-rose-300" title="Wrong or out of date">Retire</button>
                                        </form>
                                    @endcan
                                </li>
                            @endforeach
                        </ul>
                    </x-card>
                @empty
                @endforelse
                @if ($total === 0)
                    <x-empty-state message="The brain has not learned anything yet. Open a competitor and press 🧠 Learn from all reels." />
                @endif
            </div>

            {{-- How it learned --}}
            <x-card padding="md" class="self-start">
                <x-section-heading title="Learning log" subtitle="Newest first" />
                <ol class="space-y-2.5">
                    @forelse ($log as $e)
                        @php [$icon, $tone] = $events[$e->event] ?? ['·', 'text-brand-100/60']; @endphp
                        <li class="text-sm" x-data="{ open: false }">
                            <div class="flex gap-2">
                                <span class="w-4 shrink-0 text-center {{ $tone }}">{{ $icon }}</span>
                                <div class="min-w-0">
                                    <p class="text-brand-100/80"><span class="font-semibold {{ $tone }}">{{ ucfirst($e->event) }}</span> · {{ Str::limit($e->note, 220) }}</p>
                                    <p class="text-[11px] text-brand-100/40">{{ $e->created_at?->diffForHumans() }}@if ($e->account) · {{ '@'.$e->account->username }}@endif @if ($e->user) · {{ $e->user->name }}@endif
                                        @if ($e->detail)<button type="button" @click="open = !open" class="ml-1 underline">why</button>@endif</p>
                                    @if ($e->detail)<div x-show="open" x-cloak class="ai-md mt-1 text-xs">{!! \App\Support\AiMarkdown::html($e->detail) !!}</div>@endif
                                </div>
                            </div>
                        </li>
                    @empty
                        <li class="text-sm text-brand-100/50">Nothing yet.</li>
                    @endforelse
                </ol>
            </x-card>
        </div>
    </div>
</x-app-layout>
