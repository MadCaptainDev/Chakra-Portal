@props(['title'])

<div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $title }}</h2>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">
            {{ $actions }}
        </div>
    @endisset
</div>
