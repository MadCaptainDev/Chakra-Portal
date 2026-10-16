{{-- Chakra Planner and Content Dashboard are one place: the calendar, and
     each account's month against its targets (admins). --}}
@if (auth()->user()->isAdmin())
    <nav class="flex gap-1 border-b border-white/10" aria-label="Planner views">
        @foreach ([['planner.index', 'Calendar', 'planner.index'], ['content-dashboard.index', 'Targets', 'content-dashboard.*']] as [$name, $label, $match])
            @php $on = request()->routeIs($match); @endphp
            <a href="{{ route($name) }}" @if ($on) aria-current="page" @endif
               class="-mb-px inline-flex h-11 items-center border-b-2 px-4 text-sm font-semibold {{ $on ? 'border-brand-400 text-white' : 'border-transparent text-brand-100/60 hover:text-white' }}">{{ $label }}</a>
        @endforeach
    </nav>
@endif
