{{--
    Chakra Planner's page in the portal: get the app, see your signed-in
    computers, and (Manage) wire up live updates and Notion webhooks.
    The planner itself is the desktop app -- see PlannerPageController.
--}}
@php
    use App\Models\PlannerRelease;
    $webhookUrl = route('webhooks.notion');
@endphp

<x-app-layout title="Chakra Planner">
    <x-slot name="header">
        <x-page-header title="Chakra Planner · Desktop app" eyebrow="Production"
                       subtitle="The content planner app for Windows and Mac — Notion's planners, live for the whole team. Sign in with your portal email and password." />
    </x-slot>

    <div class="mx-auto max-w-4xl space-y-5">

        {{-- Get the app --}}
        <x-card padding="md">
            <x-section-heading title="Download" subtitle="Install once; the app updates itself after that." />
            <div class="grid gap-3 sm:grid-cols-3">
                @foreach (PlannerRelease::PLATFORMS as $platform => $label)
                    @php $r = $releases[$platform] ?? null; @endphp
                    <div class="rounded-xl bg-white/5 p-4 ring-1 ring-white/10">
                        <p class="font-semibold text-white">{{ $label }}</p>
                        @if ($r)
                            <p class="mt-0.5 text-xs text-brand-100/55">Version {{ $r->version }} · {{ $r->created_at->format('j M Y') }}</p>
                            <x-btn :href="route('api.planner.app.download', $platform)" size="sm" class="mt-3">Download</x-btn>
                        @else
                            <p class="mt-0.5 text-xs text-brand-100/45">Not released yet</p>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="mt-4 grid gap-3 text-sm text-brand-100/75 sm:grid-cols-2">
                <div class="rounded-lg bg-white/[0.03] p-3 ring-1 ring-white/10">
                    <p class="font-semibold text-white">Windows</p>
                    <p class="mt-1">Run the installer. If Windows says <em>"Windows protected your PC"</em>, click <strong>More info → Run anyway</strong>. Once only.</p>
                </div>
                <div class="rounded-lg bg-white/[0.03] p-3 ring-1 ring-white/10">
                    <p class="font-semibold text-white">Mac</p>
                    <p class="mt-1">Open the .dmg and drag Chakra Planner to Applications, then open it. When the Mac says it can't verify the developer: <strong>System Settings → Privacy &amp; Security → Open Anyway</strong>. Once only.</p>
                </div>
            </div>
        </x-card>

        {{-- Your computers --}}
        <x-card padding="md">
            <x-section-heading title="Signed in on" subtitle="Each computer where you have signed in to Chakra Planner." />
            @forelse ($mySessions as $s)
                <div class="flex items-center justify-between gap-3 border-t border-white/5 py-2.5 first:border-t-0">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-white">{{ $s->device_name }}</p>
                        <p class="text-xs text-brand-100/55">{{ $s->platform ?? 'Unknown' }}@if ($s->app_version) · v{{ $s->app_version }}@endif · last used {{ $s->last_used_at?->diffForHumans() ?? 'never' }}</p>
                    </div>
                    <form method="POST" action="{{ route('planner.sessions.destroy', $s->id) }}">
                        @csrf @method('DELETE')
                        <x-btn variant="secondary" size="sm">Sign out</x-btn>
                    </form>
                </div>
            @empty
                <x-empty-state message="Not signed in anywhere yet." />
            @endforelse
        </x-card>

        @if ($manage)
            {{-- Live updates --}}
            <x-card padding="md">
                <x-section-heading title="Live updates (Ably)"
                                   subtitle="Changes appear on everyone's screen at once through Ably. Without a key the app checks for changes every few seconds instead." />
                <div class="mb-4 flex flex-wrap items-center gap-3">
                    @if ($settings->realtimeEnabled())
                        <x-badge status="active">On</x-badge>
                        <span class="font-mono text-xs text-brand-100/60">{{ $settings->ablyKeyName() }}:••••••</span>
                    @else
                        <x-badge status="inactive">Off</x-badge>
                    @endif
                </div>
                <form method="POST" action="{{ route('planner.settings') }}" class="space-y-3">
                    @csrf @method('PUT')
                    <div>
                        <x-input-label for="ably_key" value="Ably API key" />
                        <x-text-input id="ably_key" name="ably_key" type="password" class="mt-1 w-full font-mono" autocomplete="new-password"
                                      placeholder="{{ $settings->realtimeEnabled() ? 'Saved — leave blank to keep it' : 'appId.keyId:secret' }}" />
                        <x-input-error :messages="$errors->get('ably_key')" class="mt-2" />
                        <p class="mt-1 text-xs text-brand-100/55">ably.com → your app → API Keys → the root key, or a key with Publish, Subscribe and Presence. Stored encrypted.</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <x-btn size="sm">Save</x-btn>
                        @if ($settings->realtimeEnabled())
                            <label class="flex items-center gap-2 text-xs text-brand-100/60">
                                <input type="checkbox" name="clear_ably" value="1" class="rounded border-white/20 bg-white/10"> Switch live updates off
                            </label>
                        @endif
                    </div>
                </form>
            </x-card>

            {{-- Notion webhooks --}}
            <x-card padding="md">
                <x-section-heading title="Notion webhooks"
                                   subtitle="Edits made in Notion reach the app within a minute, instead of waiting for Refresh or the nightly sync." />
                <ol class="list-decimal space-y-2 pl-5 text-sm text-brand-100/80">
                    <li>notion.so/profile/integrations → the studio's integration → <strong>Webhooks</strong> → <strong>Create a subscription</strong>.</li>
                    <li>Webhook URL: <code class="select-all rounded bg-white/10 px-1.5 py-0.5 font-mono text-xs text-white">{{ $webhookUrl }}</code></li>
                    <li>Tick the <strong>Page</strong> events (created, properties updated, deleted, undeleted, moved) and save.</li>
                    <li>Notion sends a token here. Copy it from below, then in Notion click <strong>Verify</strong> and paste it.</li>
                </ol>
                <div class="mt-4 rounded-lg bg-white/[0.04] p-3 ring-1 ring-white/10" x-data="{ show: false }">
                    @if ($settings->notion_webhook_secret)
                        <p class="text-xs text-brand-100/55">Token received {{ $settings->notion_webhook_secret_at?->diffForHumans() }}
                            · last event {{ $settings->notion_webhook_last_event_at?->diffForHumans() ?? 'none yet' }}</p>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <code class="select-all break-all rounded bg-white/10 px-2 py-1 font-mono text-xs text-white" x-show="show" x-cloak>{{ $settings->notion_webhook_secret }}</code>
                            <button type="button" class="text-xs font-semibold text-brand-200 hover:text-white" @click="show = !show" x-text="show ? 'Hide token' : 'Show token'"></button>
                        </div>
                        <form method="POST" action="{{ route('planner.webhook.forget') }}" class="mt-3">
                            @csrf @method('DELETE')
                            <x-btn variant="secondary" size="sm">Forget webhook token</x-btn>
                            <span class="ml-2 text-xs text-brand-100/50">Only to set the subscription up again.</span>
                        </form>
                    @else
                        <p class="text-sm text-brand-100/60">No token yet — create the subscription in Notion (steps 1–3), then reload this page.</p>
                    @endif
                </div>
            </x-card>

            {{-- Everyone's computers --}}
            <x-card padding="md">
                <x-section-heading title="All signed-in computers" subtitle="Sign one out if a laptop is lost. Taking away someone's Chakra Planner permission locks them out everywhere at once." />
                @forelse ($allSessions as $s)
                    <div class="flex items-center justify-between gap-3 border-t border-white/5 py-2 first:border-t-0">
                        <p class="min-w-0 truncate text-sm text-brand-100/85"><span class="font-semibold text-white">{{ $s->user?->name }}</span> · {{ $s->device_name }} · {{ $s->last_used_at?->diffForHumans() ?? 'never' }}</p>
                        <form method="POST" action="{{ route('planner.sessions.destroy', $s->id) }}">
                            @csrf @method('DELETE')
                            <button class="text-xs font-semibold text-red-300 hover:text-red-200">Sign out</button>
                        </form>
                    </div>
                @empty
                    <x-empty-state message="Nobody has signed in yet." />
                @endforelse
            </x-card>

            @if ($history->isNotEmpty())
                <x-card padding="md">
                    <x-section-heading title="Releases" subtitle="Uploaded by the build (POST /api/planner/app/releases)." />
                    @foreach ($history as $r)
                        <p class="border-t border-white/5 py-1.5 text-sm text-brand-100/80 first:border-t-0">
                            <span class="font-semibold text-white">{{ $r->version }}</span> · {{ PlannerRelease::PLATFORMS[$r->platform] ?? $r->platform }} · {{ $r->created_at->format('j M Y, g:i A') }}
                        </p>
                    @endforeach
                </x-card>
            @endif
        @endif
    </div>
</x-app-layout>
