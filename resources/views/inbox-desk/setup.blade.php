@php
    /**
     * Setting up the Comment & Message Checker -- see InboxDeskSetupController.
     * Three questions, in the order an admin thinks about them: which
     * accounts, what to check on each, who does it.
     */
    $checkList = old('checks', $checks);
    $picked = array_map('intval', old('account_ids', $accountIds));
    $people = array_map('intval', old('user_ids', $userIds));
@endphp

<x-app-layout title="Checker setup">
    <x-slot name="header">
        <x-page-header eyebrow="Comment & Message Checker" title="Set up the checker"
                       subtitle="Which Instagram accounts, what to check on each, and who does it.">
            <x-slot name="actions">
                <x-btn :href="route('inbox-desk.index')" variant="secondary" size="sm" icon="chat-check">Open checker</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    <form method="POST" action="{{ route('inbox-desk.setup.update') }}" class="max-w-3xl space-y-5 pb-24"
          x-data="{
              checks: {{ Js::from(array_values($checkList)) }},
              q: '',
              picked: {{ Js::from($picked) }},
              all: {{ Js::from($accounts->pluck('id')) }},
              addCheck() { if (this.checks.length < 6) { this.checks.push(''); this.$nextTick(() => this.$refs.checks.querySelector('div:last-of-type input')?.focus()); } },
          }">
        @csrf
        @method('PUT')

        <x-input-error :messages="$errors->all()" />

        {{-- ═════════ 1. Accounts ═════════ --}}
        <x-card padding="lg" class="space-y-3">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-300">Step 1</p>
                    <h2 class="text-lg font-semibold text-white">Instagram accounts</h2>
                </div>
                <div class="flex items-center gap-3 text-sm">
                    <span class="text-brand-100/60"><span x-text="picked.length"></span> picked</span>
                    <button type="button" class="text-brand-300 hover:text-white" @click="picked = [...all]">All</button>
                    <button type="button" class="text-brand-300 hover:text-white" @click="picked = []">None</button>
                </div>
            </div>
            @if ($accounts->isEmpty())
                <p class="text-sm text-brand-100/60">No Instagram accounts are connected yet. Connect them under Clients → Social Media first.</p>
            @else
                @if ($accounts->count() > 8)
                    <input type="search" x-model="q" placeholder="Find an account…"
                           class="block w-full rounded-lg border-white/15 bg-white/5 text-sm text-white placeholder:text-brand-100/40 focus:border-brand-400 focus:ring-brand-400" />
                @endif
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    @foreach ($accounts as $account)
                        @php $label = '@'.$account->username.($account->client ? ' · '.$account->client->name : ''); @endphp
                        <label x-show="!q || {{ Js::from(mb_strtolower($label)) }}.includes(q.toLowerCase())"
                               class="flex items-center gap-3 min-h-[48px] px-3 rounded-xl ring-1 cursor-pointer transition"
                               :class="picked.includes({{ $account->id }}) ? 'bg-brand-400/15 ring-brand-400/50' : 'bg-white/5 ring-white/10 hover:ring-white/25'">
                            <input type="checkbox" name="account_ids[]" value="{{ $account->id }}" x-model.number="picked"
                                   class="rounded bg-white/10 border-white/25 text-brand-400 focus:ring-brand-400" />
                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-white truncate">{{ '@'.$account->username }}</span>
                                <span class="block text-xs text-brand-100/50 truncate">{{ $account->client?->name ?? 'No client' }}@if ($account->status !== \App\Models\SocialAccount::STATUS_CONNECTED) · {{ $account->status }}@endif</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            @endif
        </x-card>

        {{-- ═════════ 2. What to check ═════════ --}}
        <x-card padding="lg" class="space-y-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-brand-300">Step 2</p>
                <h2 class="text-lg font-semibold text-white">What to check on each account</h2>
                <p class="mt-0.5 text-sm text-brand-100/60">Each one is a tile on every account. A name with "DM" or "comment" in it also shows how many new ones came in.</p>
            </div>
            <div x-ref="checks" class="space-y-2">
                <template x-for="(check, i) in checks" :key="i">
                    <div class="flex items-center gap-2">
                        <input type="text" name="checks[]" x-model="checks[i]" maxlength="60" placeholder="e.g. DMs"
                               class="flex-1 min-h-[44px] rounded-lg border-white/15 bg-white/5 text-sm text-white placeholder:text-brand-100/40 focus:border-brand-400 focus:ring-brand-400" />
                        <button type="button" @click="checks.splice(i, 1)" x-show="checks.length > 1"
                                class="shrink-0 w-11 h-11 inline-flex items-center justify-center rounded-lg text-brand-100/50 hover:text-rose-300 hover:bg-white/5"
                                aria-label="Remove">
                            <x-icon name="trash" class="w-4 h-4" />
                        </button>
                    </div>
                </template>
            </div>
            <button type="button" @click="addCheck()" x-show="checks.length < 6"
                    class="inline-flex items-center gap-1.5 text-sm font-medium text-brand-300 hover:text-white">
                <x-icon name="plus" class="w-4 h-4" /> Add a check
            </button>
            <p class="text-xs text-brand-100/45">Removing a check clears its unticked tiles. Days already ticked stay in the history.</p>
        </x-card>

        {{-- ═════════ 3. Who ═════════ --}}
        <x-card padding="lg" class="space-y-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-brand-300">Step 3</p>
                <h2 class="text-lg font-semibold text-white">Who checks</h2>
                <p class="mt-0.5 text-sm text-brand-100/60">They get "Comment &amp; Message Checker" in their sidebar. Whoever ticks a tile first, it is done for everyone. Admins always see it.</p>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                @foreach ($staff as $person)
                    <label class="flex items-center gap-2 min-h-[44px] px-3 rounded-xl bg-white/5 ring-1 ring-white/10 has-[:checked]:bg-brand-400/15 has-[:checked]:ring-brand-400/50 cursor-pointer">
                        <input type="checkbox" name="user_ids[]" value="{{ $person->id }}" @checked(in_array($person->id, $people, true))
                               class="rounded bg-white/10 border-white/25 text-brand-400 focus:ring-brand-400" />
                        <span class="text-sm text-white truncate">{{ $person->name }}</span>
                    </label>
                @endforeach
            </div>
        </x-card>

        {{-- ═════════ Schedule ═════════ --}}
        <x-card padding="lg" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="sm:col-span-2">
                <x-input-label for="title" value="Name" />
                <x-text-input id="title" name="title" class="mt-1 block w-full" :value="old('title', $checker?->title ?? 'Instagram DMs & Comments')" required />
            </div>
            <div>
                <x-input-label for="schedule_type" value="Days" />
                <select id="schedule_type" name="schedule_type" class="mt-1 block w-full min-h-[44px] rounded-md border-white/15 bg-white/5 text-sm text-white">
                    @foreach ([\App\Models\Routine::SCHEDULE_DAILY => 'Every day', \App\Models\Routine::SCHEDULE_WEEKDAYS => 'Weekdays only (Mon–Fri)'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('schedule_type', $checker?->schedule_type ?? 'daily') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <x-input-label for="catch_up_days" value="Missed days kept on the board" />
                <x-text-input id="catch_up_days" name="catch_up_days" type="number" min="0" max="31" class="mt-1 block w-full"
                              :value="old('catch_up_days', $checker?->catch_up_days ?? 3)" required />
            </div>
            <label class="sm:col-span-2 flex items-center gap-3 min-h-[44px]">
                <input type="hidden" name="is_active" value="0" />
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $checker?->is_active ?? true))
                       class="rounded bg-white/10 border-white/25 text-brand-400 focus:ring-brand-400" />
                <span class="text-sm text-white">Checker is on <span class="text-brand-100/50">— turn off to pause it without losing the setup</span></span>
            </label>
        </x-card>

        <div class="sticky bottom-3 z-10 flex justify-end">
            <x-btn type="submit" icon="check-circle" class="shadow-lg shadow-black/30">Save checker</x-btn>
        </div>
    </form>
</x-app-layout>
