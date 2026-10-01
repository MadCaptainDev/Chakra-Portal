{{-- One script, scrollable top to bottom, with a comment thread and the two
     decisions at the end. "Reject" is never a button here -- Request changes
     is the only way to send one back, and it always comes with a note so the
     writer has something to act on. --}}

<x-app-layout :title="$script->title" dark>
    <div class="space-y-6" x-data="{ requestingChanges: {{ $errors->has('note') ? 'true' : 'false' }} }">

        <div class="animate-rise-in flex items-start justify-between gap-3">
            <div class="min-w-0">
                <a href="{{ route('client.scripts') }}" class="inline-flex items-center gap-1 text-xs text-brand-100/60 hover:text-white">
                    <x-icon name="chevron-right" class="w-3.5 h-3.5 rotate-180" />
                    All scripts
                </a>
                <p class="mt-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-300">
                    {{ $script->scriptTypeTerm?->name ?? 'Script' }}
                    @if ($script->platformTerm) &middot; {{ $script->platformTerm->name }} @endif
                </p>
                <h1 class="mt-1 text-2xl sm:text-3xl font-extrabold tracking-tight">{{ $script->title }}</h1>
                @if ($script->durationLabel())
                    <p class="mt-1 text-sm text-brand-100/60">Target length: {{ $script->durationLabel() }}</p>
                @endif
            </div>
            @if ($script->sent_to_client_at)
                <span class="shrink-0 text-xs text-brand-100/50">Sent {{ $script->sent_to_client_at->diffForHumans() }}</span>
            @endif
        </div>

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
                                {{-- Unescaped by necessity: every write to a section's body
                                     goes through App\Support\Html's allowlist (see
                                     ScriptSection::setBodyAttribute), so this is safe rich
                                     text, not raw input. --}}
                                {!! $section->body !!}
                            @endif
                        </div>
                    </section>
                @empty
                    <p class="text-sm text-brand-100/50">Nothing written yet.</p>
                @endforelse
            </div>
        </x-card>

        {{-- Comments: a running conversation, separate from a decision. Posting
             here never changes the script's status -- it stays in your queue
             until you tap Approve or Request changes below. --}}
        <div id="comments">
            <x-card class="p-4 sm:p-5">
                <x-section-label dark>Comments</x-section-label>

                <div class="mt-3 space-y-3">
                    @forelse ($script->comments as $comment)
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
                    @empty
                        <p class="text-sm text-brand-100/50">No comments yet.</p>
                    @endforelse
                </div>

                <form method="POST" action="{{ route('client.scripts.comment', $script) }}" class="mt-4 pt-4 border-t border-white/10">
                    @csrf
                    <x-textarea name="body" rows="2" required maxlength="2000" class="w-full"
                                placeholder="Leave a note -- questions, a correction, anything.">{{ old('body') }}</x-textarea>
                    <x-input-error :messages="$errors->get('body')" class="mt-2" />
                    <x-btn type="submit" size="sm" class="mt-2">Comment</x-btn>
                </form>
            </x-card>
        </div>

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
