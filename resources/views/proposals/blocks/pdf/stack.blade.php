<table class="grid keep" style="width: 100%;">
    @foreach (array_chunk($block['items'], 4) as $row)
        <tr>
            @foreach ($row as $item)
                @php $logo = $item['logo'] ? \App\Support\ProposalBlocks::techLogoPath($item['logo']) : null; @endphp
                <td style="width: 25%;" class="box">
                    @if ($logo)
                        <img src="{{ \App\Support\Assets::image($logo) }}" style="width: 26pt; height: 26pt;" alt="">
                    @else
                        <div style="width: 26pt; height: 26pt; border: 1px dashed #D1D5DB; border-radius: 6pt; font-size: 6pt; color: #9CA3AF; text-align: center; line-height: 26pt;">LOGO</div>
                    @endif
                    <div class="label" style="color: #6B7280; margin-top: 6pt;">{{ $item['category'] }}</div>
                    <div style="font-weight: 600; color: #111827;">{{ $item['name'] }}</div>
                </td>
            @endforeach
            @for ($pad = count($row); $pad < 4; $pad++)
                <td style="width: 25%;"></td>
            @endfor
        </tr>
    @endforeach
</table>
