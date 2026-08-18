@php
    use App\Models\SocialAccount;

    /*
     * The client's social connections. Instagram today; the card repeats for
     * whatever comes next, which is why this reads from the platform list
     * rather than naming Instagram in the markup twice.
     *
     * Read-only for anyone without clients,manage: seeing that an account is
     * connected is useful to a coordinator; connecting or disconnecting one is
     * the same order of act as issuing a client login, and is gated the same.
     */
    $instagram = $client->socialAccounts
        ->firstWhere('platform', SocialAccount::PLATFORM_INSTAGRAM);

    $configured = App\Models\InstagramSetting::current()->isConfigured();
    $canManage = auth()->user()->can('clients.manage');
@endphp

<x-card class="p-4 sm:p-6 border border-brand-100/40">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
        <div class="min-w-0">
            <h3 class="font-semibold text-brand-900">Instagram</h3>
            <p class="text-xs text-gray-500 mt-0.5">
                Connects {{ $client->name }}'s account so the portal can read their analytics.
            </p>
        </div>

        @if ($instagram?->isConnected())
            <x-badge color="bg-green-100 text-green-800">Connected</x-badge>
        @elseif ($instagram)
            <x-badge color="bg-gray-100 text-gray-600">Disconnected</x-badge>
        @else
            <x-badge color="bg-gray-100 text-gray-600">Not connected</x-badge>
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
                     class="w-11 h-11 rounded-full object-cover ring-1 ring-gray-900/5">
            @endif
            <div class="min-w-0">
                <p class="text-sm font-semibold text-gray-900">{{ $instagram->handle() }}</p>
                <p class="text-xs text-gray-500">
                    @if ($instagram->followers_count !== null)
                        {{ number_format($instagram->followers_count) }} followers ·
                    @endif
                    Connected {{ $instagram->connected_at?->diffForHumans() }}
                </p>
            </div>
        </div>

        <dl class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm mb-4">
            <div>
                <dt class="text-xs text-gray-500">Account type</dt>
                <dd class="text-gray-900">{{ $instagram->account_type ? Str::headline(Str::lower($instagram->account_type)) : '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500">Last synced</dt>
                {{-- Phase 3 fills this. Saying "not yet" is honest; showing a
                     date that never moves would not be. --}}
                <dd class="text-gray-900">{{ $instagram->last_synced_at?->diffForHumans() ?? 'Not yet — analytics come next' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500">Access expires</dt>
                <dd class="{{ $instagram->needsRefreshSoon() ? 'text-amber-700 font-medium' : 'text-gray-900' }}">
                    {{ $instagram->token_expires_at?->diffForHumans() ?? '—' }}
                </dd>
            </div>
        </dl>

        @if ($instagram->last_error)
            <p class="mb-4 text-sm text-amber-800 bg-amber-50 ring-1 ring-amber-100 rounded-lg px-3 py-2">
                Last problem: {{ $instagram->last_error }}
            </p>
        @endif

        @if ($canManage)
            <div class="flex flex-wrap items-center gap-3">
                <a href="{{ route('instagram.insights', $client) }}"
                   class="inline-flex items-center gap-1.5 rounded-md bg-brand-400 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-brand-900 hover:bg-brand-500">
                    View Analytics
                </a>

                <a href="{{ route('instagram.report', $client) }}"
                   class="inline-flex items-center gap-1.5 rounded-md border border-brand-300 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-brand-700 hover:bg-brand-50">
                    Monthly Report
                </a>

                <form method="POST" action="{{ route('instagram.disconnect', $client) }}"
                      onsubmit="return confirm('Disconnect {{ $instagram->handle() }}? The stored access token is discarded and analytics stop updating. Anything already collected is kept, and you can reconnect the same account later.')">
                    @csrf @method('DELETE')
                    <button type="submit"
                            class="text-xs font-semibold uppercase tracking-widest text-gray-500 hover:text-red-700">
                        Disconnect
                    </button>
                </form>

                {{-- Reconnect without disconnecting first: the usual reason is a
                     token that has gone stale, and forcing a disconnect to fix
                     it is a step that exists only to satisfy the UI. --}}
                <form method="POST" action="{{ route('instagram.connect', $client) }}">
                    @csrf
                    <button type="submit"
                            class="text-xs font-semibold uppercase tracking-widest text-brand-600 hover:text-brand-800">
                        Reconnect
                    </button>
                </form>
            </div>
        @endif

    @else
        @if ($instagram?->last_error)
            <p class="mb-3 text-sm text-amber-800 bg-amber-50 ring-1 ring-amber-100 rounded-lg px-3 py-2">
                {{ $instagram->last_error }}
            </p>
        @endif

        <p class="text-sm text-gray-600 mb-4">
            The account must be a Professional one — Business or Creator. Instagram will ask
            {{ $client->name }} to sign in and approve access; the portal never sees their password.
        </p>

        @if (! $configured)
            <p class="text-sm text-amber-800 bg-amber-50 ring-1 ring-amber-100 rounded-lg px-3 py-2">
                Instagram is not set up yet.
                @if (auth()->user()->isAdmin())
                    Add the app ID and secret under <a href="{{ route('instagram-settings.edit') }}"
                       class="font-semibold underline">Setup → Instagram</a> first.
                @else
                    An admin needs to add the app credentials under Setup → Instagram first.
                @endif
            </p>
        @elseif ($canManage)
            <form method="POST" action="{{ route('instagram.connect', $client) }}">
                @csrf
                <button type="submit"
                        class="inline-flex items-center gap-2 rounded-md bg-brand-400 px-4 py-2.5 text-xs font-semibold uppercase tracking-widest text-brand-900 hover:bg-brand-500">
                    Connect Instagram
                </button>
            </form>
        @else
            <p class="text-sm text-gray-500">Only somebody with client management rights can connect this.</p>
        @endif
    @endif
</x-card>
