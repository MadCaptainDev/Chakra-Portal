@php
    use App\Support\ProposalBlocks;

    $rows = ProposalBlocks::chartRows($block['items']);
    $palette = ['#3D8CA6', '#67BCD4', '#284250', '#F59E0B', '#ABDAE7', '#9CA3AF'];

    // Donut: one circle per slice, each drawn as a dash offset round the
    // same ring. Circumference of r=15.915 is 100, so a percent IS a length.
    $offset = 25; // start at twelve o'clock
@endphp

<figure class="cp-chart cp-chart--{{ $block['kind'] }}">
    @if ($block['title'] !== '')
        <figcaption class="cp-chart__title">{{ $block['title'] }}</figcaption>
    @endif

    @if ($block['kind'] === 'donut')
        <div class="cp-chart__donut">
            <svg viewBox="0 0 42 42" class="cp-chart__ring" role="img" aria-label="{{ $block['title'] }}">
                <circle cx="21" cy="21" r="15.915" fill="none" stroke="#F3F4F6" stroke-width="6"></circle>
                @foreach ($rows as $i => $row)
                    @if ($row['percent'] > 0)
                        <circle cx="21" cy="21" r="15.915" fill="none"
                                stroke="{{ $palette[$i % count($palette)] }}" stroke-width="6"
                                stroke-dasharray="{{ round($row['percent'], 3) }} {{ round(100 - $row['percent'], 3) }}"
                                stroke-dashoffset="{{ round($offset, 3) }}"></circle>
                        @php $offset -= $row['percent']; @endphp
                    @endif
                @endforeach
            </svg>
            <ul class="cp-chart__legend">
                @foreach ($rows as $i => $row)
                    <li>
                        <i style="background: {{ $palette[$i % count($palette)] }}"></i>
                        <span>{{ $row['label'] }}</span>
                        <b>{{ round($row['percent']) }}%</b>
                    </li>
                @endforeach
            </ul>
        </div>
    @elseif ($block['kind'] === 'funnel')
        @php $first = $rows[0]['value'] ?? 0; @endphp
        <div class="cp-chart__funnel">
            @foreach ($rows as $i => $row)
                <div class="cp-chart__stage" style="--w: {{ max(18, round($row['share'] * 100)) }}%; --c: {{ $palette[$i % count($palette)] }}">
                    <div class="cp-chart__stage-bar">
                        <span>{{ $row['label'] }}</span>
                        <b>{{ ProposalBlocks::chartValue($row['value'], $block['suffix']) }}</b>
                    </div>
                    @if ($i > 0 && $first > 0)
                        <div class="cp-chart__stage-rate">{{ round($row['value'] / $first * 100) }}% of all leads</div>
                    @endif
                </div>
            @endforeach
        </div>
    @else
        <div class="cp-chart__bars">
            @foreach ($rows as $i => $row)
                <div class="cp-chart__row">
                    <span class="cp-chart__label">{{ $row['label'] }}</span>
                    <span class="cp-chart__track">
                        <span class="cp-chart__fill" style="width: {{ round($row['share'] * 100, 1) }}%; background: {{ $palette[$i % count($palette)] }}"></span>
                    </span>
                    <b class="cp-chart__value">{{ ProposalBlocks::chartValue($row['value'], $block['suffix']) }}</b>
                </div>
            @endforeach
        </div>
    @endif

    @if ($block['caption'] !== '')
        <p class="cp-chart__caption">{!! ProposalBlocks::inline($block['caption']) !!}</p>
    @endif
</figure>
