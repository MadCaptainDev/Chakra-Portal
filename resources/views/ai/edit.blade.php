@php
    /*
     * The studio's WhatsApp assistant, as an admin configures it once.
     *
     * Mirrors Setup → Notion/WhatsApp: same shape, same rule about what is
     * shown and what is never shown back. The one addition is the transcript
     * at the bottom, because the only honest answer to "is it behaving" is
     * what it actually said.
     */
    $keyed = $settings->hasKey();
    $live = $settings->isReady();
@endphp

<x-settings-layout title="Assistant">
    <x-slot name="header">
        <x-page-header
            title="WhatsApp Assistant"
            subtitle="Ask the portal a question on WhatsApp and get an answer back. Admin numbers only, and it can read everything but change nothing." />
    </x-slot>

    <div class="space-y-6">

        <x-card padding="md">
            <div class="flex flex-wrap items-center gap-x-8 gap-y-4">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-100/60 mb-1.5">API key</p>
                    @if ($keyed)
                        <x-badge status="active">Set</x-badge>
                    @else
                        <x-badge status="overdue">Not set</x-badge>
                    @endif
                </div>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-100/60 mb-1.5">Answering</p>
                    @if ($live)
                        <x-badge status="active">Live</x-badge>
                    @elseif ($settings->is_active && $keyed)
                        <x-badge status="overdue">Daily limit reached</x-badge>
                    @else
                        <x-badge status="draft">Off</x-badge>
                    @endif
                </div>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-100/60 mb-1.5">Asked today</p>
                    <p class="text-sm font-medium text-white">
                        {{ number_format($answeredToday) }}@if ($settings->daily_answer_limit > 0) <span class="text-brand-100/60">/ {{ number_format($settings->daily_answer_limit) }}</span>@endif
                    </p>
                </div>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-100/60 mb-1.5">Tokens, 30 days</p>
                    <p class="text-sm font-medium text-white">
                        {{ number_format((int) ($spend->input ?? 0)) }} in · {{ number_format((int) ($spend->output ?? 0)) }} out
                    </p>
                </div>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-100/60 mb-1.5">Last answer</p>
                    <p class="text-sm font-medium text-white">{{ $settings->last_answered_at?->diffForHumans() ?? 'Never' }}</p>
                </div>
            </div>
        </x-card>

        <x-card padding="md">
            <form method="POST" action="{{ route('ai.update') }}">
                @csrf
                @method('PUT')

                <x-section-heading
                    title="Anthropic key"
                    subtitle="console.anthropic.com → API keys → Create key. Billing has to be set up on that account before any answer works." />

                <div class="mb-4">
                    <x-input-label for="api_key" value="API key" />
                    <x-text-input id="api_key" name="api_key" type="password" class="mt-1 w-full font-mono"
                                  autocomplete="new-password"
                                  placeholder="{{ $keyed ? 'Saved — leave blank to keep it' : 'sk-ant-...' }}" />
                    <x-input-error :messages="$errors->get('api_key')" class="mt-2" />
                    <p class="text-xs text-brand-100/60 mt-1">
                        Stored encrypted and never shown again. Leaving this blank keeps the current one.
                    </p>
                </div>

                <div class="mb-4">
                    <x-input-label for="model" value="Model" />
                    <x-text-input id="model" name="model" type="text" class="mt-1 w-full font-mono"
                                  :value="old('model', $settings->modelName())" />
                    <x-input-error :messages="$errors->get('model')" class="mt-2" />
                    <p class="text-xs text-brand-100/60 mt-1">
                        <span class="font-mono">{{ \App\Models\AiSetting::DEFAULT_MODEL }}</span> unless you have a reason.
                        A cheaper one — <span class="font-mono">claude-sonnet-5</span> — costs less per answer and is
                        usually enough for looking figures up.
                    </p>
                </div>

                <div class="mb-4">
                    <x-input-label for="daily_answer_limit" value="Questions per day" />
                    <x-text-input id="daily_answer_limit" name="daily_answer_limit" type="number" min="0" max="10000"
                                  class="mt-1 w-full" :value="old('daily_answer_limit', $settings->daily_answer_limit)" />
                    <x-input-error :messages="$errors->get('daily_answer_limit')" class="mt-2" />
                    <p class="text-xs text-brand-100/60 mt-1">
                        A ceiling on the bill, not a target. Past it, WhatsApp falls back to the owner menu until
                        midnight. Zero means no limit.
                    </p>
                </div>

                <label class="flex items-start gap-3 mb-5 cursor-pointer">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $settings->is_active))
                           class="mt-0.5 rounded border-brand-100/20 bg-transparent text-brand-500 focus:ring-brand-500" />
                    <span class="text-sm text-brand-100/80">
                        <span class="font-medium text-white">Answer WhatsApp messages from admins</span><br />
                        Off means the owner menu answers instead, exactly as before. Nothing else changes.
                    </span>
                </label>

                <div class="flex items-center gap-3">
                    <x-primary-button>Save</x-primary-button>
                    @if ($settings->updatedBy)
                        <span class="text-xs text-brand-100/60">
                            Last changed by {{ $settings->updatedBy->name }}, {{ $settings->updated_at->diffForHumans() }}
                        </span>
                    @endif
                </div>
            </form>
        </x-card>

        @if ($keyed)
            <x-card padding="md">
                <x-section-heading
                    title="Remove the key"
                    subtitle="Forgets it and switches the assistant off in one go. Use this if you think it has leaked." />
                <form method="POST" action="{{ route('ai.forget') }}"
                      onsubmit="return confirm('Remove the key and switch the assistant off?')">
                    @csrf
                    @method('DELETE')
                    <x-danger-button>Remove key</x-danger-button>
                </form>
            </x-card>
        @endif

        <x-card padding="md">
            <x-section-heading
                title="What it has been asked"
                subtitle="The last 20 turns, newest first. What it looked up is listed under each answer." />

            @forelse ($recent as $message)
                <div class="py-3 {{ ! $loop->last ? 'border-b border-brand-100/10' : '' }}">
                    <div class="flex items-baseline justify-between gap-4">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-100/60">
                            {{ $message->role === 'user' ? ($message->user?->name ?? 'Admin') : 'Assistant' }}
                        </p>
                        <p class="text-[11px] text-brand-100/50 shrink-0">{{ $message->created_at->diffForHumans() }}</p>
                    </div>
                    <p class="text-sm text-white/90 mt-1 whitespace-pre-line">{{ $message->body }}</p>
                    @if ($message->tool_calls)
                        <p class="text-[11px] text-brand-100/50 mt-1.5 font-mono">
                            {{ collect($message->tool_calls)->map(fn ($call) => $call['name'].(($call['failed'] ?? false) ? ' (failed)' : ''))->implode(' · ') }}
                        </p>
                    @endif
                </div>
            @empty
                <p class="text-sm text-brand-100/60">Nothing yet. Text the studio's WhatsApp number from an admin's phone.</p>
            @endforelse
        </x-card>

    </div>
</x-settings-layout>
