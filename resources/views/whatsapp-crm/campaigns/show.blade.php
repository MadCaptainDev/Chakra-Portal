@php
    $initial = $campaign->progress() + ['status' => $campaign->status];
@endphp

<x-app-layout title="{{ $campaign->name }}">
    <x-slot name="header">
        <x-page-header :title="$campaign->name" eyebrow="WhatsApp CRM">
            <x-slot name="actions">
                @can('whatsapp-crm.edit')
                    @if (in_array($campaign->status, ['draft', 'scheduled']))
                        <form method="POST" action="{{ route('whatsapp-crm.campaigns.send-now', $campaign) }}">
                            @csrf
                            <x-btn type="submit" variant="secondary" icon="megaphone">Send now</x-btn>
                        </form>
                    @endif
                    @if (! in_array($campaign->status, ['completed', 'cancelled']))
                        <form method="POST" action="{{ route('whatsapp-crm.campaigns.cancel', $campaign) }}"
                              onsubmit="return confirm('Cancel this campaign? Messages already sent are left as they are.');">
                            @csrf
                            <x-btn type="submit" variant="danger">Cancel</x-btn>
                        </form>
                    @endif
                @endcan
                @can('whatsapp-crm.delete')
                    @if (in_array($campaign->status, ['draft', 'scheduled', 'cancelled']))
                        <form method="POST" action="{{ route('whatsapp-crm.campaigns.destroy', $campaign) }}"
                              onsubmit="return confirm('Delete this campaign?');">
                            @csrf
                            @method('DELETE')
                            <x-btn type="submit" variant="ghost" icon="trash">Delete</x-btn>
                        </form>
                    @endif
                @endcan
            </x-slot>
        </x-page-header>
    </x-slot>

    {{-- Polls campaigns/{campaign}/progress every 3s while the campaign is
         still moving (anything short of completed/failed/cancelled), and
         stops itself the moment a poll reports one of those. status/progress
         start from the page's own server-rendered values so there is no
         flash of zeros before the first fetch. --}}
    <div class="max-w-3xl mx-auto space-y-4" x-data="{
        status: @js($initial['status']),
        progress: @js($initial),
        polling: null,
        init() {
            if (this.shouldPoll()) this.start();
        },
        shouldPoll() {
            return !['completed', 'failed', 'cancelled'].includes(this.status);
        },
        start() {
            this.polling = setInterval(() => this.tick(), 3000);
        },
        async tick() {
            try {
                const response = await fetch('{{ route('whatsapp-crm.campaigns.progress', $campaign) }}');
                this.progress = await response.json();
                this.status = this.progress.status;
            } catch (e) {
                // A dropped request just waits for the next tick -- nothing on
                // screen needs to react to one missed poll.
            }
            if (!this.shouldPoll() && this.polling) {
                clearInterval(this.polling);
                this.polling = null;
            }
        },
    }">
        <x-card class="p-4 sm:p-6">
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                <div>
                    <dt class="text-brand-100/60">Status</dt>
                    <dd class="mt-0.5">
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold"
                              :class="{
                                  'bg-white/10 text-brand-100/70': !['scheduled', 'sending', 'completed', 'failed', 'cancelled'].includes(status),
                                  'bg-sky-400/15 text-sky-200': status === 'scheduled',
                                  'bg-brand-400/20 text-brand-200': status === 'sending',
                                  'bg-emerald-400/15 text-emerald-200': status === 'completed',
                                  'bg-red-400/15 text-red-200': status === 'failed' || status === 'cancelled',
                              }"
                              x-text="status.charAt(0).toUpperCase() + status.slice(1)"></span>
                    </dd>
                </div>
                <div>
                    <dt class="text-brand-100/60">Template</dt>
                    <dd class="mt-0.5 text-white">{{ $campaign->meta_template_name }} ({{ $campaign->meta_template_language }})</dd>
                </div>
                <div>
                    <dt class="text-brand-100/60">Phonebook</dt>
                    <dd class="mt-0.5 text-white">{{ $campaign->phonebook->name }}</dd>
                </div>
                <div>
                    <dt class="text-brand-100/60">Created by</dt>
                    <dd class="mt-0.5 text-white">{{ $campaign->createdBy?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-brand-100/60">Scheduled for</dt>
                    <dd class="mt-0.5 text-white">{{ $campaign->scheduled_at?->format('d M Y, h:i A') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-brand-100/60">Started</dt>
                    <dd class="mt-0.5 text-white">{{ $campaign->started_at?->format('d M Y, h:i A') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-brand-100/60">Completed</dt>
                    <dd class="mt-0.5 text-white">{{ $campaign->completed_at?->format('d M Y, h:i A') ?? '—' }}</dd>
                </div>
            </dl>
        </x-card>

        <x-card class="p-4 sm:p-6">
            <h3 class="font-semibold text-white mb-4">Progress</h3>

            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                <div class="bg-white/5 rounded-lg p-3">
                    <p class="text-[11px] font-semibold text-brand-100/60 uppercase tracking-wider">Total</p>
                    <p class="mt-1 text-xl font-bold text-white" x-text="progress.total"></p>
                </div>
                <div class="bg-white/5 rounded-lg p-3">
                    <p class="text-[11px] font-semibold text-brand-100/60 uppercase tracking-wider">Pending</p>
                    <p class="mt-1 text-xl font-bold text-white" x-text="progress.pending"></p>
                </div>
                <div class="bg-white/5 rounded-lg p-3">
                    <p class="text-[11px] font-semibold text-brand-100/60 uppercase tracking-wider">Sent</p>
                    <p class="mt-1 text-xl font-bold text-white" x-text="progress.sent"></p>
                </div>
                <div class="bg-white/5 rounded-lg p-3">
                    <p class="text-[11px] font-semibold text-brand-100/60 uppercase tracking-wider">Delivered</p>
                    <p class="mt-1 text-xl font-bold text-white" x-text="progress.delivered"></p>
                </div>
                <div class="bg-white/5 rounded-lg p-3">
                    <p class="text-[11px] font-semibold text-brand-100/60 uppercase tracking-wider">Read</p>
                    <p class="mt-1 text-xl font-bold text-white" x-text="progress.read"></p>
                </div>
                <div class="bg-white/5 rounded-lg p-3">
                    <p class="text-[11px] font-semibold text-brand-100/60 uppercase tracking-wider">Failed</p>
                    <p class="mt-1 text-xl font-bold text-red-300" x-text="progress.failed"></p>
                </div>
            </div>

            <p class="mt-4 text-xs text-brand-100/50" x-show="shouldPoll()">Updating every few seconds while this campaign is sending&hellip;</p>
        </x-card>
    </div>
</x-app-layout>
