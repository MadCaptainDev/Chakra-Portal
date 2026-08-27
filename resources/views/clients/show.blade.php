<x-app-layout title="Client">
    <x-slot name="header">
        <x-page-header :title="$client->name">
            <x-slot name="actions">
                <a href="{{ route('clients.edit', $client) }}" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-brand-400 border border-transparent rounded-md font-semibold text-xs text-brand-900 uppercase tracking-widest hover:bg-brand-500">
                    Edit Client
                </a>
            </x-slot>
        </x-page-header>
    </x-slot>

    {{-- Tabbed rather than one long scroll. A client record is four unrelated
         jobs -- who they are, what they told us, what we hold for them, and
         their login -- and stacking them meant the brief, the thing most often
         wanted, sat below a contact card and above an invoice table.

         Every panel is rendered and Alpine only switches which is visible, so
         switching costs nothing and find-in-page still reaches all of it. --}}
    {{-- The Instagram insights page links back with a #social fragment;
         reading it once on load is what makes that landing on the right tab
         instead of always Overview. --}}
    <div class="space-y-6" x-data="{ tab: 'overview' }"
         x-init="if (['overview','brief','social','competitors','credentials','login'].includes(window.location.hash.slice(1))) tab = window.location.hash.slice(1)">
        <div class="overflow-x-auto -mx-1 px-1 pb-1">
            <x-tab-nav model="tab" :tabs="array_filter([
                'overview' => ['label' => 'Overview'],
                'brief' => ['label' => 'Brand Brief', 'count' => $client->brief?->exists
                    ? $client->brief->requiredAnswered().'/'.$client->brief->requiredTotal()
                    : null],
                'social' => ['label' => 'Social Media', 'count' => $client->socialAccounts
                    ->where('status', App\Models\SocialAccount::STATUS_CONNECTED)->count() ?: null],
                'competitors' => auth()->user()->can('competitors.view')
                    ? ['label' => 'Competitors', 'count' => $competitors->count() ?: null]
                    : null,
                'credentials' => auth()->user()->can('clients.credentials')
                    ? ['label' => 'Logins We Hold', 'count' => $client->credentials()->count() ?: null]
                    : null,
                'login' => auth()->user()->can('clients.manage') ? ['label' => 'Client Login'] : null,
            ])" />
        </div>

        <div x-show="tab === 'overview'" x-cloak class="space-y-6">
        {{-- Contact info --}}
        <x-card class="p-4 sm:p-6 border border-white/10">
            <h3 class="font-semibold text-white mb-3">Contact Info</h3>
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-brand-100/60">Address</dt>
                    <dd class="text-white">{{ $client->address ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-brand-100/60">Email</dt>
                    <dd class="text-white">{{ $client->email ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-brand-100/60">Phone</dt>
                    <dd class="text-white">{{ $client->phone ?: '—' }}</dd>
                </div>
                @if ($ventureLabel)
                    <div>
                        <dt class="text-brand-100/60">Timesheet venture</dt>
                        <dd class="text-white">{{ $ventureLabel }}</dd>
                    </div>
                @endif
            </dl>
        </x-card>

        </div>

        <div x-show="tab === 'brief'" x-cloak class="space-y-6">
        {{-- The brand brief. Here rather than further down because it is client
             identity, not client accounting -- the same kind of thing as the
             contact card above it. Read-only: v1 has the client owning their
             own answers, and a staff edit would need to record who changed
             what before it could be trusted. --}}
        @php $brief = $client->brief; @endphp
        <x-card class="p-4 sm:p-6 border border-white/10" x-data="{ open: {{ $brief?->exists ? 'false' : 'true' }} }">
            <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
                <div class="min-w-0">
                    <h3 class="font-semibold text-white">Brand brief</h3>
                    <p class="text-xs text-brand-100/60 mt-0.5">
                        @if ($brief?->isSubmitted())
                            Sent in {{ $brief->submitted_at?->format('j M Y') }}
                        @elseif ($brief?->exists)
                            {{ $brief->requiredAnswered() }} of {{ $brief->requiredTotal() }} answered
                        @else
                            Not started
                        @endif
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    {{-- x-badge's `color` is a class string, not a colour name.
                         These borrow the palette the status map already uses. --}}
                    @if ($brief?->isSubmitted())
                        <x-badge color="bg-green-400/15 text-green-200">Done</x-badge>
                    @elseif ($brief?->exists)
                        <x-badge color="bg-amber-400/15 text-amber-200">In progress</x-badge>
                    @else
                        <x-badge color="bg-white/10 text-brand-100/70">Not started</x-badge>
                    @endif

                    @if ($brief?->exists)
                        <button type="button" @click="open = !open"
                                class="text-xs font-semibold uppercase tracking-widest text-brand-300 hover:text-brand-200">
                            <span x-text="open ? 'Hide' : 'Show'">Show</span>
                        </button>
                    @endif
                </div>
            </div>

            @unless ($brief?->isSubmitted())
                @if ($client->phone)
                    {{-- A wa.me deep link, not WhatsappSender. A cold reminder
                         to somebody who has not messaged the studio in the last
                         24 hours is only deliverable as a Meta-approved
                         template, and no brand_brief_reminder template exists.
                         This needs no approval, no token and no send quota, and
                         leaves the trail in the sender's own WhatsApp.

                         When a template is approved the upgrade is one route:
                         POST clients/{client}/brief/nudge behind
                         module:clients,edit calling sendTemplate(). --}}
                    @php
                        /*
                         * The public link when one has been issued, the portal
                         * URL otherwise. Most clients have no login, so a nudge
                         * pointing at a sign-in screen is a nudge that comes
                         * back as "it asked me for a password".
                         */
                        $nudge = 'Hi '.$client->name.' — before we start writing, could you fill in your brand brief? '
                            .'It takes about ten minutes: '.($brief?->publicUrl() ?? route('client.brief'));
                    @endphp
                    <div class="mb-3 flex flex-wrap items-center gap-2" x-data="{ copied: false }">
                        <a href="https://wa.me/{{ \App\Services\WhatsappSender::normalise($client->phone) }}?text={{ urlencode($nudge) }}"
                           target="_blank" rel="noopener"
                           class="inline-flex items-center gap-1.5 rounded-md bg-brand-400 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-brand-900 hover:bg-brand-500">
                            Nudge on WhatsApp
                        </a>
                        <button type="button"
                                @click="navigator.clipboard.writeText(@js($nudge)).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
                                class="text-xs font-semibold uppercase tracking-widest text-brand-100/60 hover:text-white">
                            <span x-text="copied ? 'Copied' : 'Copy message'">Copy message</span>
                        </button>
                    </div>
                @endif
            @endunless

            {{-- The share link, and what can be done with the answers.
                 Below the nudge because issuing a link is the step before
                 sending one, and above the answers because a brief that is not
                 filled in yet has nothing to read underneath. --}}
            @can('clients.edit')
                <div class="mb-4 rounded-lg bg-brand-900/40 ring-1 ring-white/10 p-3.5" x-data="{ copied: false }">
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                        <p class="text-xs font-semibold uppercase tracking-wider text-brand-100/60">Fill-in link</p>
                        @if ($brief?->isSubmitted())
                            <span class="text-[11px] text-brand-100/60">Closed — this brief has been sent in</span>
                        @elseif ($brief?->public_token)
                            <span class="text-[11px] text-brand-100/60">Open · anyone with the link can fill it once</span>
                        @endif
                    </div>

                    @if ($brief?->public_token && ! $brief->isSubmitted())
                        <div class="flex gap-2 mb-2">
                            <input type="text" readonly value="{{ $brief->publicUrl() }}" x-ref="link"
                                   class="flex-1 min-w-0 rounded-md border-white/15 bg-white/5 text-xs font-mono text-brand-100/80">
                            <button type="button"
                                    @click="$refs.link.select(); navigator.clipboard.writeText($refs.link.value); copied = true; setTimeout(() => copied = false, 2000)"
                                    class="shrink-0 rounded-md bg-white/5 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-brand-100/80 ring-1 ring-white/10 hover:bg-white/[0.09]">
                                <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                            </button>
                        </div>
                    @endif

                    <div class="flex flex-wrap items-center gap-2">
                        @unless ($brief?->isSubmitted())
                            <form method="POST" action="{{ route('clients.brief.link', $client) }}"
                                  @if ($brief?->public_token) onsubmit="return confirm('Create a new link? The current one stops working immediately.')" @endif>
                                @csrf
                                <button type="submit" class="text-xs font-semibold uppercase tracking-widest text-brand-300 hover:text-brand-200">
                                    {{ $brief?->public_token ? 'New link' : 'Create link' }}
                                </button>
                            </form>

                            @if ($brief?->public_token)
                                <form method="POST" action="{{ route('clients.brief.link.revoke', $client) }}"
                                      onsubmit="return confirm('Close this link? Answers already saved are kept, but the client cannot get back in until you issue a new one.')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="text-xs font-semibold uppercase tracking-widest text-brand-100/60 hover:text-red-200">
                                        Close link
                                    </button>
                                </form>
                            @endif
                        @else
                            {{-- The escape hatch that makes "fill it once" safe
                                 to enforce: somebody will send it with a wrong
                                 answer, and retyping a client's words for them
                                 is worse than letting them fix it. --}}
                            <form method="POST" action="{{ route('clients.brief.reopen', $client) }}"
                                  onsubmit="return confirm('Reopen this brief? The client will be able to edit and send it again.')">
                                @csrf
                                <button type="submit" class="text-xs font-semibold uppercase tracking-widest text-brand-300 hover:text-brand-200">
                                    Reopen for editing
                                </button>
                            </form>
                        @endunless

                        @if ($brief?->exists)
                            <a href="{{ route('clients.brief.export', $client) }}"
                               class="ml-auto text-xs font-semibold uppercase tracking-widest text-brand-100/60 hover:text-white">
                                Export as text
                            </a>
                        @endif
                    </div>
                </div>
            @endcan

            <div x-show="open">
                @include('clients._brief', ['brief' => $brief])
            </div>
        </x-card>

        </div>

        <div x-show="tab === 'overview'" x-cloak class="space-y-6">
        {{-- Production hours against this client --}}
        <div>
            <h3 class="font-semibold text-white mb-3">Timesheet hours</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <x-card class="p-4 sm:p-5 border border-white/10">
                    <p class="text-xs text-brand-300 uppercase tracking-wide font-semibold">Total logged</p>
                    <p class="text-2xl font-bold text-white mt-1">{{ \App\Models\TimesheetEntry::formatMinutes($timesheet['minutes']) }}</p>
                    <p class="text-xs text-brand-100/60 mt-1">{{ $timesheet['entries'] }} {{ Str::plural('entry', $timesheet['entries']) }}</p>
                </x-card>
                <x-charts.horizontal-bars
                    :items="collect($timesheet['byType'])->map(fn ($row) => ['label' => $row['label'], 'minutes' => $row['minutes']])->all()"
                    :max-minutes="max(1, collect($timesheet['byType'])->max('minutes') ?: 0)"
                    title="By type"
                    :limit="4"
                    :linkable="false"
                    empty="No timesheet hours for this client yet."
                />
            </div>
        </div>

        {{-- Invoice history --}}
        <div>
            <h3 class="font-semibold text-white mb-3">Invoice History</h3>
            @if ($invoices->isEmpty())
                <x-empty-state message="No invoices for this client yet." />
            @else
                {{-- Mobile: card list --}}
                <div class="md:hidden space-y-3">
                    @foreach ($invoices as $invoice)
                        <a href="{{ route('invoices.show', $invoice) }}" class="block bg-white/5 shadow-sm rounded-lg p-4 border border-white/10">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-semibold text-white">{{ $invoice->invoice_number ?? 'Pending' }}</span>
                                <x-badge :status="$invoice->displayStatus()" />
                            </div>
                            <div class="mt-1 flex items-center justify-between text-sm text-brand-100/60">
                                <span>{{ $invoice->invoice_date->format('d/m/Y') }}</span>
                                <span class="text-white font-semibold">{{ number_format($invoice->total, 2) }}</span>
                            </div>
                        </a>
                    @endforeach
                </div>

                {{-- Desktop: table --}}
                <x-card class="hidden md:block overflow-x-auto border border-white/10">
                    <table class="min-w-full divide-y divide-white/10">
                        <thead class="bg-white/5">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Invoice #</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Status</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-brand-100/60 uppercase">Total</th>
                                <th class="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/10">
                            @foreach ($invoices as $invoice)
                                <tr>
                                    <td class="px-6 py-4 text-sm font-medium text-white">{{ $invoice->invoice_number ?? 'Pending' }}</td>
                                    <td class="px-6 py-4 text-sm text-brand-100/60">{{ $invoice->invoice_date->format('d/m/Y') }}</td>
                                    <td class="px-6 py-4 text-sm"><x-badge :status="$invoice->displayStatus()" /></td>
                                    <td class="px-6 py-4 text-sm text-white text-right">{{ number_format($invoice->total, 2) }}</td>
                                    <td class="px-6 py-4 text-right text-sm">
                                        <a href="{{ route('invoices.show', $invoice) }}" class="text-brand-500 hover:text-brand-300 font-semibold">View</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-card>
            @endif
        </div>

        </div>

        <div x-show="tab === 'social'" x-cloak class="space-y-6">
        @include('clients._social')
        </div>

        @can('competitors.view')
        <div x-show="tab === 'competitors'" x-cloak class="space-y-6">
            @include('clients._competitors')
        </div>
        @endcan

        <div x-show="tab === 'credentials'" x-cloak class="space-y-6">
        {{-- The logins the studio holds FOR them, as opposed to the login they
             sign in with below. Only rendered for somebody granted the
             separate credentials ability. --}}
        @can('clients.credentials')
            @include('clients._credentials')
        @endcan

        </div>

        <div x-show="tab === 'login'" x-cloak class="space-y-6">
        {{-- ——— Their login ———
             Password set here and handed over by phone: the app sends no real
             mail (MAIL_MAILER is log), so an invite link would go to a log
             file. That also means no self-serve reset, which is said out loud
             below rather than discovered by a client at nine at night.

             Behind `manage`, matching the route: issuing this creates an
             account that can sign in, which is more than editing a record. --}}
        @can('clients.manage')
        <x-card class="p-4 sm:p-6 border border-white/10" x-data="{ open: false }">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="font-semibold text-white">Client login</h3>
                    <p class="mt-1 text-sm text-brand-100/70">
                        Lets {{ $client->name }} see their own invoices, published work and shoots — nothing else.
                    </p>
                </div>

                @if ($login)
                    <span class="shrink-0 inline-flex items-center px-2.5 py-1 rounded-full bg-green-400/15 text-green-200 text-[10px] font-bold uppercase tracking-wide">
                        Active
                    </span>
                @endif
            </div>

            @if ($login)
                <div class="mt-4 rounded-lg bg-brand-900/40 ring-1 ring-white/10 p-4">
                    <p class="text-sm font-semibold text-white">{{ $login->name }}</p>
                    <p class="text-xs text-brand-100/60">{{ $login->email }}</p>
                    <p class="mt-1 text-xs text-brand-100/60">
                        Created {{ $login->created_at->format('j M Y') }}
                    </p>
                </div>

                <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <form method="POST" action="{{ route('clients.login.password', $client) }}">
                        @csrf
                        @method('PUT')
                        <x-input-label for="client_password" value="Set a new password" />
                        <x-text-input id="client_password" name="password" type="text" class="mt-1 block w-full"
                                      placeholder="At least 8 characters" required />
                        <x-input-error :messages="$errors->get('password')" class="mt-2" />
                        <x-primary-button class="mt-2">Update password</x-primary-button>
                    </form>

                    <form method="POST" action="{{ route('clients.login.destroy', $client) }}" class="flex items-end"
                          onsubmit="return confirm('Revoke this login? {{ $client->name }} will not be able to sign in.');">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                                class="inline-flex items-center min-h-[44px] px-4 rounded-md border border-red-400/30 text-red-200
                                       text-xs font-semibold uppercase tracking-widest hover:bg-red-400/10 transition-colors">
                            Revoke login
                        </button>
                    </form>
                </div>

                <p class="mt-4 text-xs text-brand-100/60">
                    There is no self-serve password reset — the studio does not send email yet, so if they
                    forget it, set a new one here and tell them.
                </p>
            @else
                <div class="mt-4" x-show="! open">
                    <x-primary-button type="button" @click="open = true">Create a login</x-primary-button>
                </div>

                <form method="POST" action="{{ route('clients.login.store', $client) }}" class="mt-4 space-y-4"
                      x-show="open" x-cloak>
                    @csrf

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="login_name" value="Their name" />
                            <x-text-input id="login_name" name="name" type="text" class="mt-1 block w-full"
                                          value="{{ old('name', $client->name) }}" required />
                            <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="login_email" value="Email (used as the username)" />
                            <x-text-input id="login_email" name="email" type="email" class="mt-1 block w-full"
                                          value="{{ old('email', $client->email) }}" required />
                            <x-input-error :messages="$errors->get('email')" class="mt-2" />
                            <p class="mt-1 text-xs text-brand-100/60">Nothing is sent to it — it is how they sign in.</p>
                        </div>
                    </div>

                    <div>
                        <x-input-label for="login_password" value="Password" />
                        <x-text-input id="login_password" name="password" type="text" class="mt-1 block w-full"
                                      placeholder="At least 8 characters" required />
                        <x-input-error :messages="$errors->get('password')" class="mt-2" />
                        <p class="mt-1 text-xs text-brand-100/60">Shown in plain text so you can copy it — send it to them directly.</p>
                    </div>

                    <div class="flex items-center gap-3">
                        <x-primary-button>Create login</x-primary-button>
                        <button type="button" @click="open = false" class="text-sm text-brand-100/70 hover:text-white">Cancel</button>
                    </div>
                </form>
            @endif
        </x-card>
        @endcan

        </div>
    </div>
</x-app-layout>
