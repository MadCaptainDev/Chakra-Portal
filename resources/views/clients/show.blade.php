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

    <div class="space-y-6">
        {{-- Contact info --}}
        <x-card class="p-4 sm:p-6 border border-brand-100/40">
            <h3 class="font-semibold text-brand-900 mb-3">Contact Info</h3>
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-gray-500">Address</dt>
                    <dd class="text-gray-900">{{ $client->address ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Email</dt>
                    <dd class="text-gray-900">{{ $client->email ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Phone</dt>
                    <dd class="text-gray-900">{{ $client->phone ?: '—' }}</dd>
                </div>
                @if ($ventureLabel)
                    <div>
                        <dt class="text-gray-500">Timesheet venture</dt>
                        <dd class="text-gray-900">{{ $ventureLabel }}</dd>
                    </div>
                @endif
            </dl>
        </x-card>

        {{-- Production hours against this client --}}
        <div>
            <h3 class="font-semibold text-brand-900 mb-3">Timesheet hours</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <x-card class="p-4 sm:p-5 border border-brand-100/60">
                    <p class="text-xs text-brand-600 uppercase tracking-wide font-semibold">Total logged</p>
                    <p class="text-2xl font-bold text-brand-900 mt-1">{{ \App\Models\TimesheetEntry::formatMinutes($timesheet['minutes']) }}</p>
                    <p class="text-xs text-gray-500 mt-1">{{ $timesheet['entries'] }} {{ Str::plural('entry', $timesheet['entries']) }}</p>
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
            <h3 class="font-semibold text-brand-900 mb-3">Invoice History</h3>
            @if ($invoices->isEmpty())
                <x-empty-state message="No invoices for this client yet." />
            @else
                {{-- Mobile: card list --}}
                <div class="md:hidden space-y-3">
                    @foreach ($invoices as $invoice)
                        <a href="{{ route('invoices.show', $invoice) }}" class="block bg-white shadow-sm rounded-lg p-4 border border-brand-100/40">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-semibold text-gray-900">{{ $invoice->invoice_number ?? 'Pending' }}</span>
                                <x-badge :status="$invoice->displayStatus()" />
                            </div>
                            <div class="mt-1 flex items-center justify-between text-sm text-gray-500">
                                <span>{{ $invoice->invoice_date->format('d/m/Y') }}</span>
                                <span class="text-gray-900 font-semibold">{{ number_format($invoice->total, 2) }}</span>
                            </div>
                        </a>
                    @endforeach
                </div>

                {{-- Desktop: table --}}
                <x-card class="hidden md:block overflow-x-auto border border-brand-100/40">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-brand-50/50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice #</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                                <th class="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach ($invoices as $invoice)
                                <tr>
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $invoice->invoice_number ?? 'Pending' }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-500">{{ $invoice->invoice_date->format('d/m/Y') }}</td>
                                    <td class="px-6 py-4 text-sm"><x-badge :status="$invoice->displayStatus()" /></td>
                                    <td class="px-6 py-4 text-sm text-gray-900 text-right">{{ number_format($invoice->total, 2) }}</td>
                                    <td class="px-6 py-4 text-right text-sm">
                                        <a href="{{ route('invoices.show', $invoice) }}" class="text-brand-500 hover:text-brand-600 font-semibold">View</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-card>
            @endif
        </div>

        {{-- The logins the studio holds FOR them, as opposed to the login they
             sign in with below. Only rendered for somebody granted the
             separate credentials ability. --}}
        @can('clients.credentials')
            @include('clients._credentials')
        @endcan

        {{-- ——— Their login ———
             Password set here and handed over by phone: the app sends no real
             mail (MAIL_MAILER is log), so an invite link would go to a log
             file. That also means no self-serve reset, which is said out loud
             below rather than discovered by a client at nine at night.

             Behind `manage`, matching the route: issuing this creates an
             account that can sign in, which is more than editing a record. --}}
        @can('clients.manage')
        <x-card class="p-4 sm:p-6 border border-brand-100/40" x-data="{ open: false }">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="font-semibold text-brand-900">Client login</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        Lets {{ $client->name }} see their own invoices, published work and shoots — nothing else.
                    </p>
                </div>

                @if ($login)
                    <span class="shrink-0 inline-flex items-center px-2.5 py-1 rounded-full bg-green-100 text-green-700 text-[10px] font-bold uppercase tracking-wide">
                        Active
                    </span>
                @endif
            </div>

            @if ($login)
                <div class="mt-4 rounded-lg bg-gray-50 ring-1 ring-gray-900/5 p-4">
                    <p class="text-sm font-semibold text-gray-900">{{ $login->name }}</p>
                    <p class="text-xs text-gray-500">{{ $login->email }}</p>
                    <p class="mt-1 text-xs text-gray-500">
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
                                class="inline-flex items-center min-h-[44px] px-4 rounded-md border border-red-300 text-red-700
                                       text-xs font-semibold uppercase tracking-widest hover:bg-red-50 transition-colors">
                            Revoke login
                        </button>
                    </form>
                </div>

                <p class="mt-4 text-xs text-gray-500">
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
                            <p class="mt-1 text-xs text-gray-500">Nothing is sent to it — it is how they sign in.</p>
                        </div>
                    </div>

                    <div>
                        <x-input-label for="login_password" value="Password" />
                        <x-text-input id="login_password" name="password" type="text" class="mt-1 block w-full"
                                      placeholder="At least 8 characters" required />
                        <x-input-error :messages="$errors->get('password')" class="mt-2" />
                        <p class="mt-1 text-xs text-gray-500">Shown in plain text so you can copy it — send it to them directly.</p>
                    </div>

                    <div class="flex items-center gap-3">
                        <x-primary-button>Create login</x-primary-button>
                        <button type="button" @click="open = false" class="text-sm text-gray-600 hover:text-gray-900">Cancel</button>
                    </div>
                </form>
            @endif
        </x-card>
        @endcan

    </div>
</x-app-layout>
