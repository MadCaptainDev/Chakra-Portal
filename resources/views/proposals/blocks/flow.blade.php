@if ($block['tone'] === 'line')
    <div class="cp-flow cp-flow--line">{{ implode(' → ', $block['steps']) }}</div>
@else
    <div @class(['cp-flow', 'cp-flow--'.$block['tone'], 'cp-flow--numbered' => $block['numbered']])
         style="--cols: {{ $block['columns'] ?: max(1, count($block['steps'])) }}">
        @foreach ($block['steps'] as $i => $step)
            <div class="cp-flow__step">
                @if ($block['numbered'])
                    <span class="cp-flow__num">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}</span>
                @endif
                {{ $step }}
            </div>
        @endforeach
    </div>
@endif
