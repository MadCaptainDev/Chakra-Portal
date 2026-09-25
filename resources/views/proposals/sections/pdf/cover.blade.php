@php
    $studioLogo = $proposal->studioLogoPath($settings);
    $clientLogo = $proposal->clientLogoPath();
@endphp
<div class="cover">
    <table class="cover-grid">
        <tr>
            <td class="pad" style="height: 46mm; vertical-align: top; padding-top: 0.55in;">
                <table class="cover-top">
                    <tr>
                        <td>
                            @if ($studioLogo)
                                <img src="{{ \App\Support\Assets::image($studioLogo) }}" class="cover-brand" alt="">
                            @endif
                        </td>
                        <td class="cover-eyebrow">{{ $cover['eyebrow'] }}</td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td class="pad" style="height: 170mm; vertical-align: top; padding-top: 34mm;">
                @if ($cover['prepared_for_label'] !== '')
                    <div class="cover-for">{{ $cover['prepared_for_label'] }}</div>
                @endif
                @if ($clientLogo)
                    <img src="{{ \App\Support\Assets::image($clientLogo) }}" class="cover-logo" alt="">
                @endif
                <h1>{{ $cover['title'] ?: $proposal->title }}</h1>
                @if ($cover['subtitle'] !== '')
                    <p class="cover-sub">{{ $cover['subtitle'] }}</p>
                @endif
            </td>
        </tr>
        <tr>
            <td class="pad" style="vertical-align: top;">
                <table class="cover-meta">
                    <tr>
                        <td><div class="k">Prepared by</div><div class="v">{{ $cover['prepared_by'] }}</div></td>
                        <td><div class="k">Client</div><div class="v">{{ $cover['client_name'] ?: ($proposal->client?->name ?? '—') }}</div></td>
                        <td><div class="k">Date</div><div class="v">{{ $cover['date_label'] ?: $proposal->created_at?->format('F Y') }}</div></td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</div>
