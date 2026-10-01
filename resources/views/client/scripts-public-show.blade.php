{{--
    One script on the no-login link -- the content of
    client/scripts-show.blade.php, standalone. Same reasoning throughout as
    scripts-public.blade.php for why this isn't x-public-layout.
--}}

<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $script->title }} — {{ $client->name }}</title>
    <meta name="robots" content="noindex, nofollow">

    @include('partials.favicon')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased bg-brand-900 text-white">

<header class="border-b border-white/10">
    <div class="mx-auto max-w-3xl px-4 sm:px-6 h-16 flex items-center justify-between gap-4">
        <x-application-logo class="h-8 w-auto" />
        <p class="text-xs text-brand-100/50">{{ $client->name }}</p>
    </div>
</header>

<main class="px-4 sm:px-6 py-8 sm:py-12" x-data="{ requestingChanges: {{ $errors->has('note') ? 'true' : 'false' }} }">
    <div class="mx-auto max-w-3xl space-y-6">

        @if (session('status'))
            <div class="rounded-xl bg-brand-400/15 ring-1 ring-brand-400/25 px-4 py-3 text-sm text-brand-100" role="status">
                {{ session('status') }}
            </div>
        @endif

        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <a href="{{ route('client.scripts.public', $token) }}" class="inline-flex items-center gap-1 text-xs text-brand-100/60 hover:text-white">
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

                <form method="POST" action="{{ route('client.scripts.public.comment', [$token, $script]) }}" class="mt-4 pt-4 border-t border-white/10">
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
                <form method="POST" action="{{ route('client.scripts.public.approve', [$token, $script]) }}">
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
                <form method="POST" action="{{ route('client.scripts.public.request-changes', [$token, $script]) }}">
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
</main>

<footer class="px-4 sm:px-6 pb-10">
    <p class="mx-auto max-w-3xl text-xs text-brand-100/40">
        Questions? Reply to the message this link came from.
    </p>
</footer>

@push('styles')
<style>
    .script-read ul { list-style: disc; padding-left: 1.4rem; margin: .4rem 0; }
    .script-read ol { list-style: decimal; padding-left: 1.4rem; margin: .4rem 0; }
    .script-read p { margin: 0 0 .6rem; }
    .script-read a { color: #8ACCE0; text-decoration: underline; }
</style>
@endpush
@stack('styles')

</body>
</html>
