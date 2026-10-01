{{-- One script at a time. $script is null once the queue is empty for now;
     everything behind it is counted in $waitingCount but never listed, so a
     client cannot skip ahead to a more comfortable one and leave this one
     unanswered. --}}

<x-app-layout title="Script Approvals" dark>
    <div class="space-y-6" x-data="{ requestingChanges: {{ $errors->has('note') ? 'true' : 'false' }} }">

        <div class="animate-rise-in">
            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-300">{{ $client->name }}</p>
            <h1 class="mt-2 text-3xl sm:text-4xl font-extrabold tracking-tight">Script Approvals</h1>
            <p class="mt-2 text-sm text-brand-100/70">Review what the studio has written and send it back approved or with notes.</p>
        </div>

        @if (! $script)
            <div class="rounded-xl border border-dashed border-white/15 px-6 py-12 text-center">
                <p class="text-sm text-brand-100/70">Nothing waiting on you right now.</p>
                <p class="mt-1 text-xs text-brand-100/50">A script will show up here as soon as the studio sends one for your approval.</p>
            </div>
        @else
            <x-card class="p-5 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-300">
                            {{ $script->scriptTypeTerm?->name ?? 'Script' }}
                            @if ($script->platformTerm) &middot; {{ $script->platformTerm->name }} @endif
                        </p>
                        <h2 class="mt-1 text-2xl font-bold tracking-tight">{{ $script->title }}</h2>
                        @if ($script->durationLabel())
                            <p class="mt-1 text-sm text-brand-100/60">Target length: {{ $script->durationLabel() }}</p>
                        @endif
                    </div>
                    @if ($script->sent_to_client_at)
                        <span class="shrink-0 text-xs text-brand-100/50">
                            Sent {{ $script->sent_to_client_at->diffForHumans() }}
                        </span>
                    @endif
                </div>
            </x-card>

            <x-card class="p-6 sm:p-8">
                <div class="space-y-7">
                    @forelse ($script->sections as $section)
                        <section>
                            <h3 class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-300">{{ $section->heading }}</h3>
                            <div class="mt-2 h-px bg-white/10"></div>

                            <div class="script-read mt-3 text-[15px] leading-relaxed text-white">
                                @if ($section->isEmpty())
                                    <p class="text-brand-100/50 italic">Nothing written in this section.</p>
                                @else
                                    {{-- Unescaped by necessity, same as the staff view: every write
                                         to a section's body goes through App\Support\Html's
                                         allowlist (see ScriptSection::setBodyAttribute), so this
                                         is safe rich text, not raw input. --}}
                                    {!! $section->body !!}
                                @endif
                            </div>
                        </section>
                    @empty
                        <p class="text-sm text-brand-100/50">Nothing written yet.</p>
                    @endforelse
                </div>
            </x-card>

            @if ($script->comments->isNotEmpty())
                <x-card class="p-4 sm:p-5">
                    <x-section-label dark>Notes on this script</x-section-label>

                    <div class="mt-3 space-y-3">
                        @foreach ($script->comments as $comment)
                            <div class="flex gap-2.5">
                                <div class="w-7 h-7 shrink-0 rounded-full bg-brand-400/20 text-brand-200 text-xs font-semibold flex items-center justify-center">
                                    {{ Str::of($comment->user?->name ?? '?')->substr(0, 1) }}
                                </div>
                                <div class="min-w-0">
                                    <p class="text-xs text-brand-100/50">
                                        <span class="text-white font-semibold">{{ $comment->user?->name ?? 'Someone' }}</span>
                                        &middot; {{ $comment->created_at->diffForHumans() }}
                                    </p>
                                    <p class="text-sm text-brand-100/90 whitespace-pre-wrap">{{ $comment->body }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-card>
            @endif

            <x-card class="p-5 sm:p-6">
                <div class="flex flex-wrap items-center gap-3">
                    <form method="POST" action="{{ route('client.scripts.approve', $script) }}">
                        @csrf
                        <x-primary-button>Approve</x-primary-button>
                    </form>
                    <button type="button" @click="requestingChanges = ! requestingChanges"
                            class="inline-flex items-center min-h-[44px] px-4 rounded-md bg-white/10 ring-1 ring-white/15 text-sm font-semibold text-white hover:bg-white/15">
                        <span x-show="! requestingChanges">Request changes</span>
                        <span x-show="requestingChanges" x-cloak>Cancel</span>
                    </button>
                </div>

                <div x-show="requestingChanges" x-cloak class="mt-4 pt-4 border-t border-white/10">
                    <form method="POST" action="{{ route('client.scripts.request-changes', $script) }}">
                        @csrf
                        <x-input-label for="note" value="What needs to change?" />
                        <x-textarea id="note" name="note" rows="4" required maxlength="2000" class="mt-1 w-full"
                                    placeholder="Be as specific as you can -- this goes straight to the writer.">{{ old('note') }}</x-textarea>
                        <x-input-error :messages="$errors->get('note')" class="mt-2" />

                        <div class="mt-3 flex justify-end">
                            <x-btn type="submit" size="sm">Send back for changes</x-btn>
                        </div>
                    </form>
                </div>
            </x-card>

            @if ($waitingCount > 0)
                <p class="text-xs text-brand-100/50 text-center">
                    {{ $waitingCount }} more {{ Str::plural('script', $waitingCount) }} waiting after this one.
                </p>
            @endif
        @endif
    </div>

    @push('styles')
    <style>
        .script-read ul { list-style: disc; padding-left: 1.4rem; margin: .4rem 0; }
        .script-read ol { list-style: decimal; padding-left: 1.4rem; margin: .4rem 0; }
        .script-read p { margin: 0 0 .6rem; }
        .script-read a { color: #8ACCE0; text-decoration: underline; }
    </style>
    @endpush
</x-app-layout>
