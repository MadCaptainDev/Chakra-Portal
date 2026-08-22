@props(['status' => null, 'color' => null])

@php
    $map = [
        'pending_approval' => 'bg-amber-100 text-amber-800',
        'unpaid' => 'bg-blue-100 text-blue-800',
        'partial' => 'bg-amber-100 text-amber-800',
        'paid' => 'bg-green-100 text-green-800',
        'overdue' => 'bg-red-100 text-red-800',
        'active' => 'bg-green-100 text-green-800',
        'inactive' => 'bg-gray-100 text-gray-600',
        'suspended' => 'bg-red-100 text-red-800',
        'unread' => 'bg-brand-100 text-brand-800',
        'open' => 'bg-amber-100 text-amber-800',
        'handled' => 'bg-green-100 text-green-800',
        'completed' => 'bg-green-100 text-green-800',
        'pending' => 'bg-amber-100 text-amber-800',
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
        'shooting' => 'bg-brand-100 text-brand-800',
        'editing' => 'bg-indigo-100 text-indigo-800',
        'posting' => 'bg-teal-100 text-teal-800',
        'other' => 'bg-slate-100 text-slate-700',
        'other_task' => 'bg-slate-100 text-slate-700',
        // To-do statuses. "completed" and "cancelled" above already read right
        // for the two ends of the list, so only the live ones are new here.
        'waiting' => 'bg-gray-100 text-gray-600',
        'started' => 'bg-brand-100 text-brand-800',
        'blocked' => 'bg-red-100 text-red-800',
        // WhatsApp delivery statuses, which climb sent -> delivered -> read.
        // "failed" borrows the red that overdue and blocked already own.
        'sent' => 'bg-gray-100 text-gray-600',
        'delivered' => 'bg-blue-100 text-blue-800',
        'read' => 'bg-green-100 text-green-800',
        'failed' => 'bg-red-100 text-red-800',
    ];

    $labels = [
        'pending_approval' => 'Pending Approval',
        'unpaid' => 'Unpaid',
        'partial' => 'Partially Paid',
        'paid' => 'Paid',
        'overdue' => 'Overdue',
        'active' => 'Active',
        'inactive' => 'Inactive',
        'suspended' => 'Suspended',
        'unread' => 'New',
        'open' => 'Awaiting reply',
        'handled' => 'Handled',
        'completed' => 'Completed',
        'pending' => 'Pending',
        'idea' => 'Idea',
        'to_be_shooted' => 'To Be Shot',
        'to_be_edited' => 'To Be Edited',
        'edit_in_progress' => 'Edit In Progress',
        'under_review' => 'Under Review',
        'video_ready' => 'Video Ready',
        'scheduled' => 'Scheduled',
        'published' => 'Published',
        'canceled' => 'Canceled',
        'shooting' => 'Shooting',
        'editing' => 'Editing',
        'posting' => 'Posting',
        'other' => 'Other Task',
        'other_task' => 'Other Task',
        'waiting' => 'Waiting to Start',
        'started' => 'Started',
        'blocked' => 'Blocked',
        'cancelled' => 'Cancelled',
        'sent' => 'Sent',
        'delivered' => 'Delivered',
        'read' => 'Read',
        'failed' => 'Failed',
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
