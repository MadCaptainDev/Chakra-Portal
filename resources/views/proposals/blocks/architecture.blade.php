<div class="cp-arch">
    @foreach ($block['layers'] as $layer)
        @if ($layer['label'] !== '')
            <div class="cp-arch__label">{{ $layer['label'] }}</div>
        @endif
        <div class="cp-arch__row cp-arch__row--{{ $layer['style'] }}" style="--cols: {{ $layer['columns'] }}">
            @foreach ($layer['items'] as $item)
                <div @class(['cp-dashed' => $item['dashed']])>{{ $item['text'] }}</div>
            @endforeach
        </div>
    @endforeach
</div>
