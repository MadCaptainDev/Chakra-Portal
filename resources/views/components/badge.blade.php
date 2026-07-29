@props(['status' => null, 'color' => null])

@php
    $map = [
        'pending_approval' => 'bg-amber-100 text-amber-800',
        'unpaid' => 'bg-blue-100 text-blue-800',
        'paid' => 'bg-green-100 text-green-800',
        'overdue' => 'bg-red-100 text-red-800',
        'active' => 'bg-green-100 text-green-800',
        'inactive' => 'bg-gray-100 text-gray-600',
    ];

    $labels = [
        'pending_approval' => 'Pending Approval',
        'unpaid' => 'Unpaid',
        'paid' => 'Paid',
        'overdue' => 'Overdue',
        'active' => 'Active',
        'inactive' => 'Inactive',
    ];

    $classes = $color ?? ($map[$status] ?? 'bg-gray-100 text-gray-600');
    $label = $labels[$status] ?? ucfirst(str_replace('_', ' ', (string) $status));
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold {$classes}"]) }}>
    {{ $slot->isEmpty() ? $label : $slot }}
</span>
