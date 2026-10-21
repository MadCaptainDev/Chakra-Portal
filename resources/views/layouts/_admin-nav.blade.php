{{--
    The admin sidebar (App\Support\AdminNav): ★ Favourites, then Today,
    Content, Clients, Money, Team & setup. Hover a row for its star to pin
    it; a parent's sub-screens show while you are inside it.
--}}
@php
    use App\Support\AdminNav;

    $sections = AdminNav::sections($user);
    $favourites = AdminNav::favourites($user, $sections);
    $pinned = collect($favourites)->pluck('key')->all();
@endphp

@php
    $row = function (array $item, bool $child = false) use ($pinned) {
        return view('layouts._admin-nav-row', ['item' => $item, 'child' => $child, 'pinned' => in_array($item['key'], $pinned, true)])->render();
    };
@endphp

@if ($favourites)
    <x-nav-section label="★ Favourites">
        @foreach ($favourites as $item)
            {!! $row($item) !!}
        @endforeach
    </x-nav-section>
@endif

@foreach ($sections as $section)
    <x-nav-section :label="$section['label']">
        @foreach ($section['items'] as $item)
            {!! $row($item) !!}
            @if ($item['children'] && ($item['active'] || collect($item['children'])->contains('active', true)))
                <div class="ml-5 space-y-0.5 border-l border-white/10 pl-2">
                    @foreach ($item['children'] as $sub)
                        {!! $row($sub, true) !!}
                    @endforeach
                </div>
            @endif
        @endforeach
    </x-nav-section>
@endforeach
