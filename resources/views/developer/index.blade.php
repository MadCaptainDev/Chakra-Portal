@php
    $plain = session('mcp_token_plain');
    $token = $plain ?: 'YOUR_TOKEN';
    $writes = $tools->where('read_only', false)->count();
    $sends = $tools->where('messages_client', true)->count();

    $tabs = [
        'connect' => ['label' => 'Connect'],
        'tokens' => ['label' => 'Tokens', 'count' => $mcpTokens->count()],
        'tools' => ['label' => 'Tools', 'count' => $tools->count()],
        'activity' => ['label' => 'Activity', 'count' => $week['calls']],
        'apis' => ['label' => 'APIs'],
    ];

    $claudeCode = 'claude mcp add --transport http chakra '.$endpoint.' --header "Authorization: Bearer '.$token.'"';

    $desktopJson = json_encode(['mcpServers' => ['chakra' => [
        'command' => 'npx',
        'args' => ['-y', 'mcp-remote', $endpoint, '--header', 'Authorization: Bearer '.$token],
    ]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    $httpJson = json_encode(['mcpServers' => ['chakra' => [
        'type' => 'http',
        'url' => $endpoint,
        'headers' => ['Authorization' => 'Bearer '.$token],
    ]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    $curlList = "curl -s ".$endpoint." \\\n  -H \"Authorization: Bearer ".$token."\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/list\"}'";
    $curlCall = "curl -s ".$endpoint." \\\n  -H \"Authorization: Bearer ".$token."\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"tools/call\",\"params\":{\"name\":\"whoami\",\"arguments\":{}}}'";

    $routineAdmin = "curl -s ".url('/api/routines/whatsapp/send')." \\\n  -H \"Authorization: Bearer ".$token."\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '{\"message\":\"Morning check done: 3 shoots today.\"}'";
    $routineTo = "curl -s ".url('/api/routines/whatsapp/send-to')." \\\n  -H \"Authorization: Bearer ".$token."\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '{\"to\":\"9876543210\",\"message\":\"Hi, your reel is live.\"}'";
@endphp

<x-app-layout title="Developer">
    <x-slot name="header">
        <x-page-header title="Developer" eyebrow="Connect AI to the portal"
                       subtitle="Connect Claude or any MCP app to the studio portal. It works as you, with your permissions — never more." />
    </x-slot>

    <div x-data="{ tab: @js($tab) }" class="space-y-5">
        <div class="overflow-x-auto -mx-1 px-1">
            <x-tab-nav :tabs="$tabs" />
        </div>

        @if (session('status'))
            <div class="rounded-lg bg-emerald-400/10 ring-1 ring-emerald-400/30 px-4 py-3 text-sm text-emerald-100">{{ session('status') }}</div>
        @endif

        {{-- ───────────── Connect ───────────── --}}
        <div x-show="tab === 'connect'" class="space-y-5">
            <x-card padding="md">
                <h3 class="text-base font-semibold text-white">How it works</h3>
                <ol class="mt-3 space-y-2 text-sm text-brand-100/80 list-decimal pl-5">
                    <li>Create a token on the <button type="button" class="font-semibold text-brand-300 hover:text-brand-200" @click="tab = 'tokens'">Tokens</button> tab. It acts as <strong class="text-white">{{ $user->name }}</strong>: the AI sees and changes only what you can.</li>
                    <li>Add the server to your AI app with one of the snippets below. Create the token first and the snippets fill it in for you.</li>
                    <li>Ask in plain words, e.g. <em>"Who owes us money?"</em>, <em>"Plan a shoot for SVA on Friday 9am and put Aron on camera"</em>, <em>"Draft a quotation for Thillai"</em>.</li>
                </ol>

                <dl class="mt-5 grid gap-3 sm:grid-cols-2 text-sm">
                    <div class="rounded-lg bg-white/5 p-3">
                        <dt class="text-[11px] uppercase tracking-wider text-brand-200">Server URL</dt>
                        <dd class="mt-1 text-white break-all font-mono text-xs">{{ $endpoint }}</dd>
                    </div>
                    <div class="rounded-lg bg-white/5 p-3">
                        <dt class="text-[11px] uppercase tracking-wider text-brand-200">Transport</dt>
                        <dd class="mt-1 text-white">Streamable HTTP (POST, JSON replies, stateless)</dd>
                    </div>
                    <div class="rounded-lg bg-white/5 p-3">
                        <dt class="text-[11px] uppercase tracking-wider text-brand-200">Auth</dt>
                        <dd class="mt-1 text-white font-mono text-xs">Authorization: Bearer chakra_…</dd>
                    </div>
                    <div class="rounded-lg bg-white/5 p-3">
                        <dt class="text-[11px] uppercase tracking-wider text-brand-200">Server</dt>
                        <dd class="mt-1 text-white">chakra-portal v{{ $serverVersion }} · {{ $tools->count() }} tools for you</dd>
                    </div>
                </dl>
            </x-card>

            <x-card padding="md">
                <h3 class="text-base font-semibold text-white">Claude Code</h3>
                <p class="mt-1 text-sm text-brand-100/70">Run once in a terminal. Then type <code class="text-brand-200">/mcp</code> in Claude Code to check it is connected.</p>
                @include('developer._code', ['code' => $claudeCode])
            </x-card>

            <x-card padding="md">
                <h3 class="text-base font-semibold text-white">Claude Desktop</h3>
                <p class="mt-1 text-sm text-brand-100/70">Settings → Developer → Edit Config, paste into <code class="text-brand-200">claude_desktop_config.json</code>, then restart Claude. Needs Node.js installed.</p>
                @include('developer._code', ['code' => $desktopJson])
            </x-card>

            <x-card padding="md">
                <h3 class="text-base font-semibold text-white">Cursor, VS Code and other MCP apps</h3>
                <p class="mt-1 text-sm text-brand-100/70">Any app that supports remote HTTP servers with custom headers. The file name depends on the app (e.g. <code class="text-brand-200">.cursor/mcp.json</code>, <code class="text-brand-200">.mcp.json</code>).</p>
                @include('developer._code', ['code' => $httpJson])
            </x-card>

            <x-card padding="md">
                <h3 class="text-base font-semibold text-white">Test it by hand</h3>
                @include('developer._code', ['label' => 'List your tools', 'code' => $curlList])
                @include('developer._code', ['label' => 'Call one', 'code' => $curlCall])
            </x-card>

            <x-card padding="md">
                <h3 class="text-base font-semibold text-white">Rules every AI is given</h3>
                <p class="mt-1 text-sm text-brand-100/70">Sent to the AI when it connects, before any tool is used.</p>
                <div class="mt-3 rounded-lg bg-black/20 ring-1 ring-white/10 p-4 text-sm text-brand-50 leading-relaxed">{{ $instructions }}</div>

                <h4 class="mt-5 text-sm font-semibold text-white">Built-in safety</h4>
                <ul class="mt-2 space-y-1.5 text-sm text-brand-100/80 list-disc pl-5">
                    <li>Each tool lists exactly what it accepts; anything else is rejected before it runs.</li>
                    <li>A client name that fits more than one client is refused with both names, so the AI has to ask you.</li>
                    <li>Tools that WhatsApp a client are flagged to the AI app, so it can ask you before it sends.</li>
                    <li>No tool records payments, approves invoices, reveals client passwords, touches salaries or deletes clients.</li>
                    <li>Every call is written to the <button type="button" class="font-semibold text-brand-300 hover:text-brand-200" @click="tab = 'activity'">Activity</button> log. Revoke a token and it stops working straight away.</li>
                </ul>
            </x-card>
        </div>

        {{-- ───────────── Tokens ───────────── --}}
        <div x-show="tab === 'tokens'" x-cloak>
            <x-card padding="md">
                @include('profile.partials.mcp-tokens')
                <p class="mt-6 text-xs text-brand-100/60">
                    One token per device or app, so you can revoke one without breaking the others.
                    The same tokens work for the Routines WhatsApp API on the APIs tab{{ $user->isAdmin() ? '' : ' (admins only)' }}.
                </p>
            </x-card>
        </div>

        {{-- ───────────── Tools ───────────── --}}
        <div x-show="tab === 'tools'" x-cloak x-data="{ q: '' }" class="space-y-5">
            <x-card padding="md">
                <p class="text-sm text-brand-100/80">
                    The {{ $tools->count() }} tools your token gets — {{ $tools->count() - $writes }} read-only,
                    {{ $writes }} that change data, {{ $sends }} that message a client.
                    Someone with fewer permissions sees fewer tools. The descriptions below are exactly what the AI reads.
                </p>
                <div class="mt-3 flex flex-wrap gap-2 text-[11px]">
                    <span class="px-2 py-0.5 rounded-full bg-emerald-400/15 text-emerald-200">Read-only</span>
                    <span class="px-2 py-0.5 rounded-full bg-amber-400/15 text-amber-200">Changes data</span>
                    <span class="px-2 py-0.5 rounded-full bg-red-400/15 text-red-200">Messages a client</span>
                    <span class="px-2 py-0.5 rounded-full bg-red-500/25 text-red-100">Deletes</span>
                    <span class="px-2 py-0.5 rounded-full bg-violet-400/15 text-violet-200">Admin only</span>
                    <span class="px-2 py-0.5 rounded-full bg-sky-400/15 text-sky-200">Also on WhatsApp assistant</span>
                </div>
                <input type="search" x-model="q" placeholder="Search tools…"
                       class="mt-4 w-full rounded-lg bg-white/5 border-white/10 text-sm text-white placeholder-brand-100/40 focus:border-brand-300 focus:ring-brand-300">
            </x-card>

            @foreach ($toolGroups as $group => $groupTools)
                <section>
                    <h3 class="text-[11px] font-semibold uppercase tracking-wider text-brand-300 mb-2">{{ $group }}</h3>
                    <div class="space-y-2">
                        @foreach ($groupTools as $t)
                            <div x-data="{ open: false }"
                                 x-show="q === '' || @js(strtolower($t['name'].' '.$t['title'].' '.$t['description'])).includes(q.toLowerCase())"
                                 class="rounded-xl bg-white/5 ring-1 ring-white/10">
                                <button type="button" @click="open = ! open" class="w-full text-left p-4 flex items-start gap-3">
                                    <div class="min-w-0 flex-1">
                                        <p class="font-semibold text-white">{{ $t['title'] }}</p>
                                        <p class="mt-0.5 font-mono text-xs text-brand-200">{{ $t['name'] }}</p>
                                        <div class="mt-2 flex flex-wrap gap-1.5 text-[11px]">
                                            @if ($t['destructive'])
                                                <span class="px-2 py-0.5 rounded-full bg-red-500/25 text-red-100">Deletes</span>
                                            @elseif ($t['messages_client'])
                                                <span class="px-2 py-0.5 rounded-full bg-red-400/15 text-red-200">Messages a client</span>
                                            @elseif ($t['read_only'])
                                                <span class="px-2 py-0.5 rounded-full bg-emerald-400/15 text-emerald-200">Read-only</span>
                                            @else
                                                <span class="px-2 py-0.5 rounded-full bg-amber-400/15 text-amber-200">Changes data</span>
                                            @endif
                                            @if ($t['admin_only'])
                                                <span class="px-2 py-0.5 rounded-full bg-violet-400/15 text-violet-200">Admin only</span>
                                            @endif
                                            @if ($t['permission'])
                                                <span class="px-2 py-0.5 rounded-full bg-white/10 text-brand-100/70">needs {{ $t['permission'] }}</span>
                                            @endif
                                            @if ($t['whatsapp_assistant'])
                                                <span class="px-2 py-0.5 rounded-full bg-sky-400/15 text-sky-200">WhatsApp assistant</span>
                                            @endif
                                        </div>
                                    </div>
                                    <x-icon name="chevron-right" class="w-4 h-4 mt-1 shrink-0 text-brand-100/50 transition-transform" ::class="open && 'rotate-90'" />
                                </button>
                                <div x-show="open" x-cloak class="px-4 pb-4 border-t border-white/10">
                                    <p class="mt-3 text-sm text-brand-100/85 leading-relaxed">{{ $t['description'] }}</p>
                                    @if ($t['inputs'])
                                        <div class="mt-3 overflow-x-auto">
                                            <table class="w-full text-xs">
                                                <thead>
                                                    <tr class="text-left text-brand-200">
                                                        <th class="py-1.5 pr-3 font-semibold">Input</th>
                                                        <th class="py-1.5 pr-3 font-semibold">Type</th>
                                                        <th class="py-1.5 font-semibold">What to pass</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach ($t['inputs'] as $in)
                                                        <tr class="border-t border-white/5 align-top">
                                                            <td class="py-1.5 pr-3 font-mono text-white whitespace-nowrap">{{ $in['name'] }}@if ($in['required'])<span class="text-red-300">*</span>@endif</td>
                                                            <td class="py-1.5 pr-3 text-brand-100/70">{{ $in['type'] }}</td>
                                                            <td class="py-1.5 text-brand-100/80">{{ $in['description'] }}</td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                            <p class="mt-1.5 text-[11px] text-brand-100/50"><span class="text-red-300">*</span> required</p>
                                        </div>
                                    @else
                                        <p class="mt-3 text-xs text-brand-100/60">Takes no inputs.</p>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>

        {{-- ───────────── Activity ───────────── --}}
        <div x-show="tab === 'activity'" x-cloak class="space-y-5">
            <div class="grid grid-cols-3 gap-3">
                <x-card padding="sm">
                    <p class="text-[11px] uppercase tracking-wider text-brand-200">Calls, 7 days</p>
                    <p class="mt-1 text-2xl font-bold text-white tabular-nums">{{ number_format($week['calls']) }}</p>
                </x-card>
                <x-card padding="sm">
                    <p class="text-[11px] uppercase tracking-wider text-brand-200">Refused / failed</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums {{ $week['failed'] ? 'text-amber-200' : 'text-white' }}">{{ number_format($week['failed']) }}</p>
                </x-card>
                <x-card padding="sm">
                    <p class="text-[11px] uppercase tracking-wider text-brand-200">Avg time</p>
                    <p class="mt-1 text-2xl font-bold text-white tabular-nums">{{ $week['avg_ms'] }}<span class="text-sm font-medium text-brand-100/60"> ms</span></p>
                </x-card>
            </div>

            <x-card padding="none" class="overflow-hidden">
                <p class="px-4 pt-4 text-sm text-brand-100/70">
                    The last 100 tool calls{{ $user->isAdmin() ? ' from everyone' : ' made with your tokens' }}.
                    "Refused" is the tool saying no (wrong client, missing field) — the AI is shown why. Results are not stored.
                </p>
                <div class="mt-3 divide-y divide-white/5">
                    @forelse ($logs as $log)
                        <div x-data="{ open: false }" class="px-4 py-3">
                            <button type="button" @click="open = ! open" class="w-full flex items-center gap-3 text-left">
                                <span class="shrink-0 w-2 h-2 rounded-full {{ $log->ok ? 'bg-emerald-400' : 'bg-amber-400' }}"></span>
                                <span class="min-w-0 flex-1">
                                    <span class="font-mono text-sm text-white">{{ $log->tool }}</span>
                                    <span class="block text-xs text-brand-100/60 truncate">
                                        {{ $log->user?->name ?? 'Deleted user' }}
                                        @if ($log->token) · {{ $log->token->name }} @endif
                                        · {{ $log->created_at->format('j M, H:i') }}
                                        · {{ $log->duration_ms }} ms
                                    </span>
                                </span>
                                <span class="shrink-0 text-[11px] font-semibold {{ $log->ok ? 'text-emerald-200' : 'text-amber-200' }}">{{ $log->ok ? 'OK' : 'Refused' }}</span>
                            </button>
                            <div x-show="open" x-cloak class="mt-2 pl-5 space-y-2">
                                @if ($log->error)
                                    <p class="text-xs text-amber-100">{{ $log->error }}</p>
                                @endif
                                <pre class="overflow-x-auto rounded-md bg-black/30 p-2 text-[11px] text-brand-50 whitespace-pre-wrap break-all">{{ $log->arguments }}</pre>
                            </div>
                        </div>
                    @empty
                        <p class="px-4 py-10 text-center text-sm text-brand-100/60">No calls yet. Connect an app on the Connect tab and ask it something.</p>
                    @endforelse
                </div>
            </x-card>
        </div>

        {{-- ───────────── APIs ───────────── --}}
        <div x-show="tab === 'apis'" x-cloak class="space-y-5">
            <x-card padding="md">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-base font-semibold text-white">MCP server</h3>
                        <p class="mt-1 text-sm text-brand-100/70">For AI apps. Set-up on the Connect tab, every tool on the Tools tab.</p>
                    </div>
                    <span class="shrink-0 px-2 py-0.5 rounded-full bg-white/10 text-[11px] text-brand-100/70">MCP token</span>
                </div>
                <p class="mt-3 font-mono text-xs text-white break-all">POST {{ $endpoint }}</p>
            </x-card>

            <x-card padding="md">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-base font-semibold text-white">Routines WhatsApp API</h3>
                        <p class="mt-1 text-sm text-brand-100/70">For Claude Routines and scripts: send a WhatsApp from the studio number. Needs an <strong class="text-white">admin's</strong> MCP token. Max 20 calls a minute.</p>
                    </div>
                    <span class="shrink-0 px-2 py-0.5 rounded-full bg-violet-400/15 text-[11px] text-violet-200">Admin token</span>
                </div>

                <div class="mt-4 space-y-1 text-sm">
                    <p class="font-mono text-xs text-white">POST {{ url('/api/routines/whatsapp/send') }}</p>
                    <p class="text-brand-100/70 text-xs">To every admin with a phone number.</p>
                    <p class="pt-2 font-mono text-xs text-white">POST {{ url('/api/routines/whatsapp/send-to') }}</p>
                    <p class="text-brand-100/70 text-xs">To one number (<code>to</code>).</p>
                </div>

                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead><tr class="text-left text-brand-200"><th class="py-1.5 pr-3">Field</th><th class="py-1.5">Meaning</th></tr></thead>
                        <tbody class="text-brand-100/80">
                            <tr class="border-t border-white/5"><td class="py-1.5 pr-3 font-mono text-white">message</td><td>Text to send. Free text only reaches someone who messaged the studio in the last 24 hours.</td></tr>
                            <tr class="border-t border-white/5"><td class="py-1.5 pr-3 font-mono text-white">to</td><td>send-to only. Indian number, with or without +91.</td></tr>
                            <tr class="border-t border-white/5"><td class="py-1.5 pr-3 font-mono text-white">type</td><td><code>text</code> (default) or <code>template</code> — use a template outside the 24-hour window.</td></tr>
                            <tr class="border-t border-white/5"><td class="py-1.5 pr-3 font-mono text-white">template</td><td>Approved template name, when type is template.</td></tr>
                            <tr class="border-t border-white/5"><td class="py-1.5 pr-3 font-mono text-white">params</td><td>Template body values, in order.</td></tr>
                        </tbody>
                    </table>
                </div>

                @include('developer._code', ['label' => 'Message the admins', 'code' => $routineAdmin])
                @include('developer._code', ['label' => 'Message one number', 'code' => $routineTo])
                <p class="mt-3 text-xs text-brand-100/60">Without a token: 401. With a non-admin token: 403. "sent" means WhatsApp accepted it, not that it was delivered.</p>
            </x-card>

            <x-card padding="md">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-base font-semibold text-white">Phone widget API</h3>
                        <p class="mt-1 text-sm text-brand-100/70">Your day at a glance for a home-screen widget. Uses a widget key (not an MCP token), made on your <a href="{{ route('profile.edit') }}" class="font-semibold text-brand-300 hover:text-brand-200">profile</a>.</p>
                    </div>
                    <span class="shrink-0 px-2 py-0.5 rounded-full bg-white/10 text-[11px] text-brand-100/70">Widget key</span>
                </div>
                <p class="mt-3 font-mono text-xs text-white break-all">GET {{ route('api.widget.today') }}</p>
            </x-card>

            @if ($canSeeSaasApi)
                <x-card padding="md">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="text-base font-semibold text-white">SaaS backup &amp; license API</h3>
                            <p class="mt-1 text-sm text-brand-100/70">For App Studio software running on a client's own server: backups, AMC license check, config. Each product has its own token.</p>
                        </div>
                        <span class="shrink-0 px-2 py-0.5 rounded-full bg-white/10 text-[11px] text-brand-100/70">Product token</span>
                    </div>
                    <a href="{{ route('developer.saas-api') }}"
                       class="mt-4 inline-flex items-center gap-1.5 min-h-[40px] px-4 rounded-md bg-brand-400 text-brand-900 text-xs font-semibold uppercase tracking-widest hover:bg-brand-500 transition-colors">
                        Open interactive reference
                    </a>
                </x-card>
            @endif
        </div>
    </div>
</x-app-layout>
