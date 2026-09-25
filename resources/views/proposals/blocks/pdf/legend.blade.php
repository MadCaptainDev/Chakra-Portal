@php $count = max(1, count($block['items'])); @endphp
<div class="box keep" style="margin-bottom: 14pt; background: #F9FAFB;">
    @if ($block['title'] !== '')
        <div style="font-size: 7pt; letter-spacing: 1pt; text-transform: uppercase; color: #6B7280; font-weight: 600; margin-bottom: 6pt;">{{ $block['title'] }}</div>
    @endif
    <table>
        <tr>
            @foreach ($block['items'] as $item)
                <td style="width: {{ round(100 / $count, 2) }}%; vertical-align: top; padding: 0 8pt 0 0; font-size: 8pt; line-height: 1.35; color: #4B5563;">
                    <span class="tag tag-{{ $item['tag'] }}">{{ $item['label'] ?: \App\Support\ProposalBlocks::TAGS[$item['tag']] }}</span>
                    @if ($item['note'] !== '')
                        <div style="margin-top: 4pt;">{{ $item['note'] }}</div>
                    @endif
                </td>
            @endforeach
        </tr>
    </table>
</div>
