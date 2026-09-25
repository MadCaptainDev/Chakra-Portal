<div class="cp-stack">
    @foreach ($block['items'] as $item)
        @php $logo = $item['logo'] ? \App\Support\ProposalBlocks::techLogoPath($item['logo']) : null; @endphp
        <div class="cp-stack__item">
            @if ($logo)
                <div class="cp-stack__logo"><img src="{{ asset($logo) }}" alt="{{ $item['name'] }} logo"></div>
            @else
                <div class="cp-stack__logo cp-stack__logo--empty">Logo</div>
            @endif
            <div>
                <div class="cp-stack__cat">{{ $item['category'] }}</div>
                <div class="cp-stack__name">{{ $item['name'] }}</div>
            </div>
        </div>
    @endforeach
</div>
