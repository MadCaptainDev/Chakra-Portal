<table class="keep" style="margin: 9pt 0 7pt; font-size: 9pt;">
    <tr>
        <td style="width: 1%; white-space: nowrap; vertical-align: top; padding-right: 8pt;">
            <span class="tag tag-{{ $block['tag'] }}">{{ \App\Support\ProposalBlocks::TAGS[$block['tag']] }}</span>
        </td>
        <td style="vertical-align: top;">{!! \App\Support\ProposalBlocks::inline($block['text']) !!}</td>
    </tr>
</table>
