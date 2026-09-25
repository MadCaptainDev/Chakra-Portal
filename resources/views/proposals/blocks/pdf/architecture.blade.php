<div class="box keep" style="border-radius: 10pt; padding: 11pt; margin: 7pt 0 11pt;">
    @foreach ($block['layers'] as $layer)
        @if ($layer['label'] !== '')
            <div class="label" style="color: #6B7280; margin-top: {{ $loop->first ? 0 : 7 }}pt;">{{ $layer['label'] }}</div>
        @endif
        @php
            $cols = $layer['columns'];
            $width = floor(100 / $cols);
            $dark = $layer['style'] === 'dark';
        @endphp
        <div @if ($dark) class="dark" style="border-radius: 8pt; padding: 4pt;" @endif>
            <table class="grid" style="border-spacing: {{ $dark ? 4 : 5 }}pt;">
                @foreach (array_chunk($layer['items'], $cols) as $row)
                    <tr>
                        @foreach ($row as $item)
                            @php
                                $style = match (true) {
                                    $item['dashed'] => 'background: #F3F4F6; color: #4B5563; border: 1px dashed #D1D5DB;',
                                    $dark => 'background: #284250; color: #FFFFFF; font-weight: 400;',
                                    $layer['style'] === 'outline' => 'border: 1px solid #E5E7EB; color: #1F2937;',
                                    default => 'background: #F2F9FC; color: #284250;',
                                };
                            @endphp
                            <td class="chip" style="width: {{ $width }}%; {{ $style }}">{{ $item['text'] }}</td>
                        @endforeach
                        @for ($pad = count($row); $pad < $cols; $pad++)
                            <td style="width: {{ $width }}%;"></td>
                        @endfor
                    </tr>
                @endforeach
            </table>
        </div>
    @endforeach
</div>
