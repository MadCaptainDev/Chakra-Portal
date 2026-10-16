{{-- Editor Updates and Editor Output are one page: the day, and the months.
     Output ranks people against each other, so it stays admin-only and a
     manager sees no tabs at all. --}}
@if (auth()->user()->isAdmin())
<nav class="flex gap-1 border-b border-white/10" aria-label="Editor Updates views">
    @foreach ([['editor-updates', 'Today', 'Live day, review and ready queues'], ['editors.index', 'Output', 'Videos and hours by month']] as [$name, $label, $hint])
        @php $on = request()->routeIs($name) || ($name === 'editor-updates' && request()->routeIs('editor-updates.editor')); @endphp
        <a href="{{ route($name) }}" title="{{ $hint }}" @if ($on) aria-current="page" @endif
           class="-mb-px inline-flex h-11 items-center border-b-2 px-4 text-sm font-semibold {{ $on ? 'border-brand-400 text-white' : 'border-transparent text-brand-100/60 hover:text-white' }}">{{ $label }}</a>
    @endforeach
</nav>
@endif
