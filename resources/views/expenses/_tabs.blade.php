@php
    $monthQuery = [];
    if (request()->filled('month')) {
        $monthQuery['month'] = request('month');
    } elseif (isset($month) && $month instanceof \Illuminate\Support\Carbon) {
        $monthQuery['month'] = $month->format('Y-m');
    }

    $tabs = [
        [
            'label' => 'Overview',
            'route' => 'expenses.index',
            'active' => request()->routeIs('expenses.*'),
            'url' => route('expenses.index', $monthQuery),
        ],
        [
            'label' => 'Salaries',
            'route' => 'salaries.index',
            'active' => request()->routeIs('salaries.*'),
            'url' => route('salaries.index', $monthQuery),
        ],
        [
            'label' => 'Bills',
            'route' => 'bills.index',
            'active' => request()->routeIs('bills.*'),
            'url' => route('bills.index', $monthQuery),
        ],
        [
            'label' => 'EMI',
            'route' => 'emi.index',
            'active' => request()->routeIs('emi.*'),
            'url' => route('emi.index', $monthQuery),
        ],
        [
            'label' => 'One-time',
            'route' => 'other.index',
            'active' => request()->routeIs('other.*'),
            'url' => route('other.index', $monthQuery),
        ],
    ];
@endphp

<nav class="flex gap-1 overflow-x-auto border-b border-gray-200 -mb-px" aria-label="Expenses sections">
    @foreach ($tabs as $tab)
        <a href="{{ $tab['url'] }}"
           @class([
               'shrink-0 px-4 py-2.5 text-sm font-semibold border-b-2 -mb-px transition-colors',
               'border-brand-500 text-brand-600' => $tab['active'],
               'border-transparent text-gray-500 hover:text-gray-800 hover:border-gray-300' => ! $tab['active'],
           ])>
            {{ $tab['label'] }}
        </a>
    @endforeach
</nav>
