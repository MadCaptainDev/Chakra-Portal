@if ($block['path'] !== '' && is_file(public_path(\App\Support\ProposalBlocks::pdfImagePath($block['path']))))
    <div class="keep" style="margin: 6pt 0 10pt; text-align: center;">
        <img src="{{ \App\Support\Assets::image(\App\Support\ProposalBlocks::pdfImagePath($block['path'])) }}"
             style="width: {{ $block['size'] === 'medium' ? '70%' : '100%' }}; border-radius: 6pt;" alt="">
        @if ($block['caption'] !== '')
            <div style="font-size: 8pt; color: #6B7280; margin-top: 4pt;">{!! \App\Support\ProposalBlocks::inline($block['caption']) !!}</div>
        @endif
    </div>
@endif
