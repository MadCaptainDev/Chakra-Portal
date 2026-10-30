{{--
    Instagram accounts whose connection has expired or been revoked: numbers
    stop syncing until the client logs in to Instagram again. Shown to whoever
    can reconnect them (clients.manage) -- the button is on the client's
    Social tab, and needs the client's own Instagram login.
--}}
@php($reconnect = auth()->user()->can('clients.manage') ? \App\Models\SocialAccount::needingReconnect() : collect())
@if ($reconnect->isNotEmpty())
    <section class="rounded-xl bg-amber-400/[0.07] ring-1 ring-amber-400/30 p-4 sm:p-5">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="font-semibold text-amber-100">
                Instagram to reconnect
                <span class="ml-1 rounded-full bg-amber-400/20 px-2 text-xs text-amber-200">{{ $reconnect->count() }}</span>
            </h2>
            <p class="text-xs text-amber-100/60">Views and posts stop updating until the client logs in again.</p>
        </div>
        <ul class="mt-3 space-y-1.5">
            @foreach ($reconnect as $account)
                <li class="flex items-center gap-3 rounded-lg bg-white/[0.04] px-3 py-2.5">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-white">{{ $account->client->name }}</p>
                        <p class="truncate text-xs text-brand-100/55">
                            {{ $account->handle() }} ·
                            @if ($account->status === \App\Models\SocialAccount::STATUS_REVOKED)
                                access removed
                            @elseif ($account->token_expires_at)
                                expired {{ $account->token_expires_at->format('j M') }}
                            @else
                                not connected
                            @endif
                        </p>
                    </div>
                    <a href="{{ route('clients.show', $account->client) }}#social"
                       class="inline-flex min-h-[40px] shrink-0 items-center rounded-lg bg-amber-400 px-3.5 text-xs font-bold text-brand-900 hover:bg-amber-300">Reconnect</a>
                </li>
            @endforeach
        </ul>
        <p class="mt-2.5 text-[11px] text-brand-100/50">Open the client with them (or share your screen), tap Connect (or Reconnect) on the Social tab, and let them log in to their own account.</p>
    </section>
@endif
