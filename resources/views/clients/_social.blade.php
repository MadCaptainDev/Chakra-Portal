@php
    use App\Models\SocialAccount;

    /*
     * The client's social connections. Instagram today; the card repeats for
     * whatever comes next, which is why this reads from the platform list
     * rather than naming Instagram in the markup twice.
     *
     * Two contexts share this one partial: a staff member managing a
     * client's connection from clients/show.blade.php (bare
     * @include('clients._social'), every var below defaulted to that
     * behaviour), and a client connecting their own account self-service
     * from client/social.blade.php, which passes every var explicitly.
     * Read-only for staff without clients,manage: seeing that an account is
     * connected is useful to a coordinator; connecting or disconnecting one
     * is the same order of act as issuing a client login, and is gated the
     * same. The client is always allowed to manage their own.
     */
    $instagram = $client->socialAccounts
        ->firstWhere('platform', SocialAccount::PLATFORM_INSTAGRAM);

    $configured = App\Models\InstagramSetting::current()->isConfigured();
    $selfService ??= false;
    $canManage ??= $selfService || auth()->user()->can('clients.manage');
    $connectRoute ??= route('instagram.connect', $client);
    $disconnectRoute ??= route('instagram.disconnect', $client);
    $insightsRoute ??= route('instagram.insights', $client);
    $reportRoute ??= route('instagram.report', $client);
@endphp

<x-card class="p-4 sm:p-6 border border-white/10">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
        <div class="min-w-0">
            <h3 class="font-semibold text-white">Instagram</h3>
            <p class="text-xs text-brand-100/60 mt-0.5">
                @if ($selfService)
                    Connect your account so Chakra Groups can see your analytics.
                @else
                    Connects {{ $client->name }}'s account so the portal can read their analytics.
                @endif
            </p>
        </div>

        @if ($instagram?->isConnected())
            <x-badge color="bg-green-400/15 text-green-200">Connected</x-badge>
        @elseif ($instagram)
            <x-badge color="bg-white/10 text-brand-100/70">Disconnected</x-badge>
        @else
            <x-badge color="bg-white/10 text-brand-100/70">Not connected</x-badge>
        @endif
    </div>

    @if ($instagram?->isConnected())
        <div class="flex flex-wrap items-center gap-3 mb-4">
            @if ($instagram->profile_picture_url)
                {{-- Instagram's CDN URLs expire. A broken image here is cosmetic
                     and must not look like a broken connection, so it simply
                     hides itself. --}}
                <img src="{{ $instagram->profile_picture_url }}" alt=""
                     onerror="this.remove()"
                     class="w-11 h-11 rounded-full object-cover ring-1 ring-white/10">
            @endif
            <div class="min-w-0">
                <p class="text-sm font-semibold text-white">{{ $instagram->handle() }}</p>
                <p class="text-xs text-brand-100/60">
                    @if ($instagram->followers_count !== null)
                        {{ number_format($instagram->followers_count) }} followers ·
                    @endif
                    Connected {{ $instagram->connected_at?->diffForHumans() }}
                </p>
            </div>
        </div>

        <dl class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm mb-4">
            <div>
                <dt class="text-xs text-brand-100/60">Account type</dt>
                <dd class="text-white">{{ $instagram->account_type ? Str::headline(Str::lower($instagram->account_type)) : '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-brand-100/60">Last synced</dt>
                {{-- Phase 3 fills this. Saying "not yet" is honest; showing a
                     date that never moves would not be. --}}
                <dd class="text-white">{{ $instagram->last_synced_at?->diffForHumans() ?? 'Not yet — analytics come next' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-brand-100/60">Access expires</dt>
                <dd class="{{ $instagram->needsRefreshSoon() ? 'text-amber-200 font-medium' : 'text-white' }}">
                    {{ $instagram->token_expires_at?->diffForHumans() ?? '—' }}
                </dd>
            </div>
        </dl>

        @if ($instagram->last_error)
            <p class="mb-4 text-sm text-amber-200 bg-amber-400/10 ring-1 ring-amber-400/20 rounded-lg px-3 py-2">
                Last problem: {{ $instagram->last_error }}
            </p>
        @endif

        @if ($canManage)
            <div class="flex flex-wrap items-center gap-3">
                <a href="{{ $insightsRoute }}"
                   class="inline-flex items-center gap-1.5 rounded-md bg-brand-400 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-brand-900 hover:bg-brand-500">
                    View Analytics
                </a>

                <a href="{{ $reportRoute }}"
                   class="inline-flex items-center gap-1.5 rounded-md border border-brand-300 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-brand-200 hover:bg-white/10">
                    Monthly Report
                </a>

                <form method="POST" action="{{ $disconnectRoute }}"
                      onsubmit="return confirm('Disconnect {{ $instagram->handle() }}? The stored access token is discarded and analytics stop updating. Anything already collected is kept, and you can reconnect the same account later.')">
                    @csrf @method('DELETE')
                    <button type="submit"
                            class="text-xs font-semibold uppercase tracking-widest text-brand-100/60 hover:text-red-200">
                        Disconnect
                    </button>
                </form>

                {{-- Reconnect without disconnecting first: the usual reason is a
                     token that has gone stale, and forcing a disconnect to fix
                     it is a step that exists only to satisfy the UI. --}}
                <form method="POST" action="{{ $connectRoute }}">
                    @csrf
                    <button type="submit"
                            class="text-xs font-semibold uppercase tracking-widest text-brand-300 hover:text-brand-200">
                        Reconnect
                    </button>
                </form>
            </div>
        @endif

    @else
        @if ($instagram?->last_error)
            <p class="mb-3 text-sm text-amber-200 bg-amber-400/10 ring-1 ring-amber-400/20 rounded-lg px-3 py-2">
                {{ $instagram->last_error }}
            </p>
        @endif

        <p class="text-sm text-brand-100/70 mb-4">
            @if ($selfService)
                The account must be a Professional one — Business or Creator. Instagram will ask
                you to sign in and approve access; the portal never sees your password.
            @else
                The account must be a Professional one — Business or Creator. Instagram will ask
                {{ $client->name }} to sign in and approve access; the portal never sees their password.
            @endif
        </p>

        @if (! $configured)
            <p class="text-sm text-amber-200 bg-amber-400/10 ring-1 ring-amber-400/20 rounded-lg px-3 py-2">
                @if ($selfService)
                    Instagram connection is not set up yet. Contact Chakra Groups.
                @else
                    Instagram is not set up yet.
                    @if (auth()->user()->isAdmin())
                        Add the app ID and secret under <a href="{{ route('instagram-settings.edit') }}"
                           class="font-semibold underline">Setup → Instagram</a> first.
                    @else
                        An admin needs to add the app credentials under Setup → Instagram first.
                    @endif
                @endif
            </p>
        @elseif ($canManage)
            <form method="POST" action="{{ $connectRoute }}">
                @csrf
                <button type="submit"
                        class="inline-flex items-center gap-2 rounded-md bg-brand-400 px-4 py-2.5 text-xs font-semibold uppercase tracking-widest text-brand-900 hover:bg-brand-500">
                    Connect Instagram
                </button>
            </form>
        @else
            <p class="text-sm text-brand-100/60">Only somebody with client management rights can connect this.</p>
        @endif
    @endif
</x-card>
