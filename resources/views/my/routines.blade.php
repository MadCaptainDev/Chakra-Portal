@php
    use App\Models\RoutineOccurrence;
@endphp

<x-app-layout title="My Routines">
    <x-slot name="header">
        <x-page-header title="My Routines" eyebrow="Your work"
                       subtitle="Tick today’s duties. Overdue ones stay until done." />
    </x-slot>

    <div class="max-w-3xl space-y-6">
        @include('my._routine-section', ['title' => 'Overdue', 'items' => $overdue, 'tone' => 'overdue'])
        @include('my._routine-section', ['title' => 'Today', 'items' => $todayItems, 'tone' => 'today'])
        @include('my._routine-section', ['title' => 'Coming up', 'items' => $upcoming, 'tone' => 'upcoming'])
    </div>
</x-app-layout>
