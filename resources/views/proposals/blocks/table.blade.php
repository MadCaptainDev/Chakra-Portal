@php
    $columns = max(count($block['header']), ...array_map('count', $block['rows'] ?: [[]]));
    $lastRow = count($block['rows']) - 1;
@endphp

<div class="cp-table-wrap">
    <table>
        @if ($block['header'])
            <thead>
                <tr>
                    @for ($c = 0; $c < $columns; $c++)
                        <th @class(['cp-right' => $block['last_col_right'] && $c === $columns - 1 && $c > 0])
                            @if ($c === 0 && $block['first_col_width']) style="width: {{ $block['first_col_width'] }}%" @endif>
                            {{ $block['header'][$c] ?? '' }}
                        </th>
                    @endfor
                </tr>
            </thead>
        @endif
        <tbody>
            @foreach ($block['rows'] as $r => $row)
                <tr @class(['cp-strong' => $block['last_row_bold'] && $r === $lastRow])>
                    @for ($c = 0; $c < $columns; $c++)
                        <td @class([
                            'cp-strong' => $block['first_col_bold'] && $c === 0,
                            'cp-right' => $block['last_col_right'] && $c === $columns - 1 && $c > 0,
                        ])>{!! \App\Support\ProposalBlocks::inline($row[$c] ?? '') !!}</td>
                    @endfor
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
