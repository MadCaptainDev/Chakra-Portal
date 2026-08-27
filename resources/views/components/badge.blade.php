@props(['status' => null, 'color' => null])

@php
    // One convention across the whole map, drawn for the brand-900 ground:
    // a 15%-alpha wash of the 400 shade behind the 200 shade of the same hue.
    // Solid bg-*-100 chips (what these were) read as light-mode leftovers on
    // navy and lose the text to a near-white-on-pastel contrast.
    $map = [
        'pending_approval' => 'bg-amber-400/15 text-amber-200',
        'unpaid' => 'bg-sky-400/15 text-sky-200',
        'partial' => 'bg-amber-400/15 text-amber-200',
        'paid' => 'bg-emerald-400/15 text-emerald-200',
        'overdue' => 'bg-red-400/15 text-red-200',
        'active' => 'bg-emerald-400/15 text-emerald-200',
        'inactive' => 'bg-white/10 text-brand-100/70',
        'suspended' => 'bg-red-400/15 text-red-200',
        'unread' => 'bg-brand-400/20 text-brand-200',
        'open' => 'bg-amber-400/15 text-amber-200',
        'handled' => 'bg-emerald-400/15 text-emerald-200',
        'completed' => 'bg-emerald-400/15 text-emerald-200',
        // Portal shoot booking statuses (Shoot::STATUSES).
        'planned' => 'bg-amber-400/15 text-amber-200',
        'confirmed' => 'bg-brand-400/20 text-brand-200',
        'pending' => 'bg-amber-400/15 text-amber-200',
        'idea' => 'bg-white/10 text-brand-100/70',
        'to_be_shooted' => 'bg-purple-400/15 text-purple-200',
        'to_be_shot' => 'bg-purple-400/15 text-purple-200',
        'to_be_edited' => 'bg-amber-400/15 text-amber-200',
        'edit_in_progress' => 'bg-amber-400/15 text-amber-200',
        'under_review' => 'bg-indigo-400/15 text-indigo-200',
        'video_ready' => 'bg-teal-400/15 text-teal-200',
        'scheduled' => 'bg-sky-400/15 text-sky-200',
        'published' => 'bg-emerald-400/15 text-emerald-200',
        'canceled' => 'bg-red-400/15 text-red-200',
        'cancelled' => 'bg-red-400/15 text-red-200',
        'shooting' => 'bg-brand-400/20 text-brand-200',
        'editing' => 'bg-indigo-400/15 text-indigo-200',
        'posting' => 'bg-teal-400/15 text-teal-200',
        'other' => 'bg-white/10 text-brand-100/70',
        'other_task' => 'bg-white/10 text-brand-100/70',
        // To-do statuses. "completed" and "cancelled" above already read right
        // for the two ends of the list, so only the live ones are new here.
        'waiting' => 'bg-white/10 text-brand-100/70',
        'started' => 'bg-brand-400/20 text-brand-200',
        'blocked' => 'bg-red-400/15 text-red-200',
        // WhatsApp delivery statuses, which climb sent -> delivered -> read.
        // "failed" borrows the red that overdue and blocked already own.
        'sent' => 'bg-white/10 text-brand-100/70',
        'delivered' => 'bg-sky-400/15 text-sky-200',
        'read' => 'bg-emerald-400/15 text-emerald-200',
        'failed' => 'bg-red-400/15 text-red-200',
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
        'planned' => 'Planned',
        'confirmed' => 'Confirmed',
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

    $classes = $color ?? ($map[$key] ?? 'bg-white/10 text-brand-100/70');
    $label = $labels[$key] ?? ucfirst(str_replace('_', ' ', (string) $status));
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold {$classes}"]) }}>
    {{ $slot->isEmpty() ? $label : $slot }}
</span>
