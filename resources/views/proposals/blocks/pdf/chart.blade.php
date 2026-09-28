@php
    use App\Support\ProposalBlocks;

    /*
     * Every chart kind draws as table bars here. DomPDF does not render
     * inline SVG and has no flexbox, so the web page's ring and centred
     * funnel have no faithful PDF equivalent -- a donut becomes its
     * percentages as bars, which carries the same information.
     */
    $rows = ProposalBlocks::chartRows($block['items']);
    $palette = ['#3D8CA6', '#67BCD4', '#284250', '#F59E0B', '#ABDAE7', '#9CA3AF'];
    $first = $rows[0]['value'] ?? 0;
@endphp

<div class="box keep" style="margin: 6pt 0 10pt;">
    @if ($block['title'] !== '')
        <div style="font-weight: 700; color: #111827; margin-bottom: 6pt;">{{ $block['title'] }}</div>
    @endif

    <table style="width: 100%; border-collapse: collapse;">
        @foreach ($rows as $i => $row)
            @php
                $share = $block['kind'] === 'donut' ? $row['percent'] / 100 : $row['share'];
                $width = max(1, round($share * 100));
                $value = $block['kind'] === 'donut'
                    ? round($row['percent']).'%'
                    : ProposalBlocks::chartValue($row['value'], $block['suffix']);
            @endphp
            <tr>
                <td style="width: 32%; padding: 2.5pt 6pt 2.5pt 0; font-size: 9pt; color: #374151;">{{ $row['label'] }}</td>
                <td style="padding: 2.5pt 0;">
                    <div style="background: #F3F4F6; height: 9pt; border-radius: 4pt;">
                        <div style="width: {{ $width }}%; height: 9pt; border-radius: 4pt; background: {{ $palette[$i % count($palette)] }};"></div>
                    </div>
                </td>
                <td style="width: 16%; padding: 2.5pt 0 2.5pt 6pt; text-align: right; font-size: 9pt; font-weight: 700; color: #111827;">
                    {{ $value }}
                    @if ($block['kind'] === 'funnel' && $i > 0 && $first > 0)
                        <div style="font-size: 7pt; font-weight: 400; color: #6B7280;">{{ round($row['value'] / $first * 100) }}%</div>
                    @endif
                </td>
            </tr>
        @endforeach
    </table>

    @if ($block['caption'] !== '')
        <div style="font-size: 8pt; color: #6B7280; margin-top: 5pt;">{!! ProposalBlocks::inline($block['caption']) !!}</div>
    @endif
</div>
