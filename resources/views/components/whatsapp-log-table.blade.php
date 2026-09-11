@props(['logs'])

{{-- Shared by Invoice and Quotation show pages -- one send history table for
     both, driven off the same polymorphic WhatsappSendLog. --}}
@if ($logs->isEmpty())
    <p class="text-sm text-brand-100/60">Nothing sent yet.</p>
@else
    <div class="border rounded-md overflow-x-auto">
        <table class="min-w-full divide-y divide-white/10">
            <thead class="bg-brand-900/40">
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-medium text-brand-100/60 uppercase">When</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-brand-100/60 uppercase">Phone</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-brand-100/60 uppercase">Status</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-brand-100/60 uppercase">Sent By</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-brand-100/60 uppercase">Detail</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/10">
                @foreach ($logs as $log)
                    <tr>
                        <td class="px-4 py-2 text-sm text-brand-100/60 whitespace-nowrap">{{ $log->created_at->format('d/m/Y g:i A') }}</td>
                        <td class="px-4 py-2 text-sm text-white whitespace-nowrap">{{ $log->phone }}</td>
                        <td class="px-4 py-2 text-sm"><x-badge :status="$log->status" /></td>
                        <td class="px-4 py-2 text-sm text-brand-100/60 whitespace-nowrap">{{ $log->sentBy?->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-sm text-red-300">{{ $log->error }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
