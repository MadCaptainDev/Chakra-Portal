@php
    /**
     * One row from RoutineDutyList::nest(). A single subtask is a plain
     * duty, rendered exactly as before; more than one is an account
     * checklist under one card.
     */
@endphp

@if ($task['subtasks']->count() === 1)
    @include('my._routine-duty', ['duty' => $task['subtasks']->first()])
@else
    @include('my._routine-checklist', ['task' => $task])
@endif
