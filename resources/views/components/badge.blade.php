@props(['status' => null, 'color' => null])

@php
    $map = [
        'pending_approval' => 'bg-amber-100 text-amber-800',
        'unpaid' => 'bg-blue-100 text-blue-800',
        'paid' => 'bg-green-100 text-green-800',
        'overdue' => 'bg-red-100 text-red-800',
        'active' => 'bg-green-100 text-green-800',
        'inactive' => 'bg-gray-100 text-gray-600',
        'idea' => 'bg-gray-100 text-gray-600',
        'to_be_shooted' => 'bg-purple-100 text-purple-800',
        'to_be_shot' => 'bg-purple-100 text-purple-800',
        'to_be_edited' => 'bg-amber-100 text-amber-800',
        'edit_in_progress' => 'bg-amber-100 text-amber-800',
        'under_review' => 'bg-indigo-100 text-indigo-800',
        'video_ready' => 'bg-teal-100 text-teal-800',
        'scheduled' => 'bg-blue-100 text-blue-800',
        'published' => 'bg-green-100 text-green-800',
        'canceled' => 'bg-red-100 text-red-800',
        'cancelled' => 'bg-red-100 text-red-800',
    ];

    $labels = [
        'pending_approval' => 'Pending Approval',
        'unpaid' => 'Unpaid',
        'paid' => 'Paid',
        'overdue' => 'Overdue',
        'active' => 'Active',
        'inactive' => 'Inactive',
        'idea' => 'Idea',
        'to_be_shooted' => 'To Be Shot',
        'to_be_edited' => 'To Be Edited',
        'edit_in_progress' => 'Edit In Progress',
        'under_review' => 'Under Review',
        'video_ready' => 'Video Ready',
        'scheduled' => 'Scheduled',
        'published' => 'Published',
        'canceled' => 'Canceled',
    ];

    // Invoice/recurring statuses arrive as snake_case; Notion statuses arrive as
    // free-text labels (e.g. "Video Ready", "Under Review") with whatever casing
    // and spacing was set up in Notion, so normalize before lookup.
    $key = $status ? strtolower(str_replace([' ', '-'], '_', trim((string) $status))) : null;

    $classes = $color ?? ($map[$key] ?? 'bg-gray-100 text-gray-600');
    $label = $labels[$key] ?? ucfirst(str_replace('_', ' ', (string) $status));
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold {$classes}"]) }}>
    {{ $slot->isEmpty() ? $label : $slot }}
</span>
