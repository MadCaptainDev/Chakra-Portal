@php
    /*
    | The WhatsApp setup screen.
    |
    | Written to be worked through top to bottom with the Meta dashboard open in
    | the next tab: the two values Meta asks for are first and copyable, the two
    | badges say whether it worked, and the log at the bottom is the proof.
    */
    $verified = $settings->verified_at !== null;
    $receiving = $settings->isReceiving();
@endphp

<x-app-layout title="WhatsApp">
    <x-slot name="header">
        <x-page-header
            title="WhatsApp Integration"
            subtitle="Connect the studio's Meta app so incoming messages and delivery statuses arrive here." />
    </x-slot>

    <div class="max-w-3xl mx-auto space-y-6">

        {{-- Where the connection stands. Two questions, two answers, before any
             form -- so the common visit ("is it still working?") is over in a
             glance and nobody edits a field to find out. --}}
        <x-card padding="md">
            <div class="flex flex-wrap items-center gap-x-8 gap-y-4">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Callback verified</p>
                    @if ($verified)
                        <x-badge status="active">Verified {{ $settings->verified_at->diffForHumans() }}</x-badge>
                    @else
                        <x-badge status="pending">Not verified yet</x-badge>
                    @endif
                </div>

                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Accepting events</p>
                    @if ($receiving)
                        <x-badge status="active">App secret set</x-badge>
                    @else
                        <x-badge status="overdue">Blocked — no app secret</x-badge>
                    @endif
                </div>

                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 mb-1.5">Last event</p>
                    <p class="text-sm text-gray-900 font-medium">
                        {{ $settings->last_event_at?->diffForHumans() ?? 'Nothing received yet' }}
                    </p>
                </div>
            </div>

            @unless ($receiving)
                <p class="mt-4 text-sm text-red-700 bg-red-50 ring-1 ring-red-100 rounded-lg px-3 py-2">
                    Until the app secret below is filled in, every event Meta sends is refused. The endpoint cannot tell
                    Meta apart from anyone else who has learned the URL without it, so it turns everything away rather
                    than trusting a stranger.
                </p>
            @endunless
        </x-card>

        {{-- Step one: the two values Meta asks for. --}}
        <x-card padding="md">
            <x-section-heading
                title="Paste these into Meta"
                subtitle="Meta dashboard → WhatsApp → Configuration → Edit webhook." />

            <div class="space-y-4"
                 x-data="{
                     copied: null,
                     copy(key, el) {
                         el.select();
                         navigator.clipboard?.writeText(el.value).catch(() => document.execCommand('copy'));
                         this.copied = key;
                         setTimeout(() => { if (this.copied === key) this.copied = null }, 2000);
                     }
                 }">

                <div>
                    <x-input-label value="Callback URL" />
                    <div class="mt-1 flex gap-2">
                        <input type="text" readonly value="{{ $settings->callbackUrl() }}"
                               x-ref="url"
                               class="flex-1 min-w-0 rounded-md border-gray-300 bg-gray-50 text-sm font-mono text-gray-800 focus:border-brand-500 focus:ring-brand-500">
                        <x-secondary-button type="button" @click="copy('url', $refs.url)" class="shrink-0">
                            <span x-text="copied === 'url' ? 'Copied' : 'Copy'">Copy</span>
                        </x-secondary-button>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">
                        Fixed, and safe to share — it is useless without the verify token and the signature. Built from
                        APP_URL, so it stays the same whichever hostname you happen to be signed in through.
                    </p>
                </div>

                <div>
                    <x-input-label value="Verify token" />
                    <div class="mt-1 flex gap-2">
                        <input type="text" readonly value="{{ $settings->verify_token }}"
                               x-ref="token"
                               class="flex-1 min-w-0 rounded-md border-gray-300 bg-gray-50 text-sm font-mono text-gray-800 focus:border-brand-500 focus:ring-brand-500">
                        <x-secondary-button type="button" @click="copy('token', $refs.token)" class="shrink-0">
                            <span x-text="copied === 'token' ? 'Copied' : 'Copy'">Copy</span>
                        </x-secondary-button>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">
                        Generated for you, so there is never a moment where someone has to invent a secret on the spot.
                        Meta sends it back once, when you press <span class="font-medium">Verify and save</span>.
                    </p>
                </div>
            </div>

            <form method="POST" action="{{ route('whatsapp.rotate') }}" class="mt-5 pt-4 border-t border-gray-100"
                  onsubmit="return confirm('Generate a new verify token? The current one stops working immediately and you will have to re-verify the webhook in the Meta dashboard.')">
                @csrf
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs text-gray-500 max-w-md">
                        Rotate the token if it has been shared somewhere it should not have been. You will need to paste
                        the new one into Meta and verify again.
                    </p>
                    <x-secondary-button type="submit">Generate new token</x-secondary-button>
                </div>
            </form>
        </x-card>

        {{-- Step two: what Meta gives back. --}}
        <x-card padding="md">
            <form method="POST" action="{{ route('whatsapp.update') }}">
                @csrf
                @method('PUT')

                <x-section-heading
                    title="From the Meta dashboard"
                    subtitle="The app secret is required. The rest is reference, and what sending will read later." />

                <div class="mb-4">
                    <x-input-label for="app_secret" value="App secret" />
                    <x-text-input id="app_secret" name="app_secret" type="password" class="mt-1 font-mono"
                                  autocomplete="new-password"
                                  placeholder="{{ $receiving ? 'Saved — leave blank to keep it' : 'Meta dashboard → App settings → Basic → App secret' }}" />
                    <x-input-error :messages="$errors->get('app_secret')" class="mt-2" />
                    <p class="text-xs text-gray-500 mt-1">
                        Every incoming event is signed with this and checked before it is stored — it is what proves a
                        POST came from Meta. Stored encrypted and never shown again; leaving this blank keeps the
                        current one.
                    </p>
                </div>

                <div class="grid sm:grid-cols-2 gap-4 mb-4">
                    <div>
                        <x-input-label for="phone_number_id" value="Phone number ID" />
                        <x-text-input id="phone_number_id" name="phone_number_id" type="text" class="mt-1 font-mono"
                                      value="{{ old('phone_number_id', $settings->phone_number_id) }}" />
                        <x-input-error :messages="$errors->get('phone_number_id')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="business_account_id" value="WhatsApp business account ID" />
                        <x-text-input id="business_account_id" name="business_account_id" type="text" class="mt-1 font-mono"
                                      value="{{ old('business_account_id', $settings->business_account_id) }}" />
                        <x-input-error :messages="$errors->get('business_account_id')" class="mt-2" />
                    </div>
                </div>

                <div class="mb-6">
                    <x-input-label for="display_phone_number" value="Display phone number" />
                    <x-text-input id="display_phone_number" name="display_phone_number" type="text" class="mt-1"
                                  value="{{ old('display_phone_number', $settings->display_phone_number) }}"
                                  placeholder="+91 98765 43210" />
                    <x-input-error :messages="$errors->get('display_phone_number')" class="mt-2" />
                    <p class="text-xs text-gray-500 mt-1">The number clients actually see. Reference only.</p>
                </div>

                <div class="flex items-center gap-3">
                    <x-primary-button>Save</x-primary-button>
                    @if ($settings->updatedBy)
                        <span class="text-xs text-gray-500">
                            Last changed by {{ $settings->updatedBy->name }}, {{ $settings->updated_at->diffForHumans() }}
                        </span>
                    @endif
                </div>
            </form>
        </x-card>

        {{-- Step three: the proof. Meta's "Test" button on the webhook config
             sends a sample event, and it lands here within a second -- which is
             the fastest way to know the whole chain works end to end. --}}
        <x-card padding="md">
            <x-section-heading
                title="Recent events"
                :subtitle="$totalEvents > 0
                    ? number_format($totalEvents).' received in total — newest first'
                    : 'Press Test on any field in the Meta webhook config; it should appear here immediately.'" />

            @if ($events->isEmpty())
                <x-empty-state message="Nothing has arrived yet." />
            @else
                <div class="overflow-x-auto -mx-4 sm:-mx-6">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500 border-b border-gray-200">
                                <th class="px-4 sm:px-6 py-2">When</th>
                                <th class="px-3 py-2">Type</th>
                                <th class="px-3 py-2">From / To</th>
                                <th class="px-4 sm:px-6 py-2">Detail</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($events as $event)
                                <tr class="align-top">
                                    <td class="px-4 sm:px-6 py-2.5 whitespace-nowrap text-gray-500 text-xs">
                                        {{ ($event->occurred_at ?? $event->received_at)->format('d M, H:i') }}
                                    </td>
                                    <td class="px-3 py-2.5 whitespace-nowrap">
                                        @if ($event->type === App\Models\WhatsappWebhookEvent::TYPE_MESSAGE)
                                            <x-badge status="unread">In · {{ $event->message_type ?? 'message' }}</x-badge>
                                        @elseif ($event->type === App\Models\WhatsappWebhookEvent::TYPE_STATUS)
                                            <x-badge :status="$event->status" />
                                        @elseif ($event->type === App\Models\WhatsappWebhookEvent::TYPE_ERROR)
                                            <x-badge status="overdue">Error</x-badge>
                                        @else
                                            <x-badge status="other">{{ $event->field ?? 'other' }}</x-badge>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5 whitespace-nowrap">
                                        <p class="text-gray-900">{{ $event->contact_name ?? '—' }}</p>
                                        @if ($event->wa_id)
                                            <p class="text-xs text-gray-500 font-mono">{{ $event->wa_id }}</p>
                                        @endif
                                    </td>
                                    <td class="px-4 sm:px-6 py-2.5 text-gray-700">
                                        {{ Str::limit($event->summary, 90) ?: '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>

    </div>
</x-app-layout>
