@php
    $cover = $section['data'];
    $studioLogo = $proposal->studioLogoPath($settings);
    $clientLogo = $proposal->clientLogoPath();
@endphp

<article class="cp-sheet cp-cover" id="section-{{ $section['key'] }}">
    <div class="cp-cover__top">
        @if ($studioLogo)
            <img src="{{ asset($studioLogo) }}" alt="{{ $cover['prepared_by'] }}" class="cp-cover__brand">
        @else
            <span></span>
        @endif
        <div class="cp-cover__eyebrow">{{ $cover['eyebrow'] }}</div>
    </div>

    <div>
        @if ($cover['prepared_for_label'] !== '')
            <div class="cp-cover__for">{{ $cover['prepared_for_label'] }}</div>
        @endif
        @if ($clientLogo)
            <img src="{{ asset($clientLogo) }}" alt="{{ $cover['client_name'] ?: $cover['title'] }}" class="cp-cover__logo">
        @endif
        <h1>{{ $cover['title'] ?: $proposal->title }}</h1>
        @if ($cover['subtitle'] !== '')
            <p class="cp-cover__subtitle">{{ $cover['subtitle'] }}</p>
        @endif
    </div>

    <dl class="cp-cover__meta">
        <div>
            <dt>Prepared by</dt>
            <dd>{{ $cover['prepared_by'] }}</dd>
        </div>
        <div>
            <dt>Client</dt>
            <dd>{{ $cover['client_name'] ?: ($proposal->client?->name ?? '—') }}</dd>
        </div>
        <div>
            <dt>Date</dt>
            <dd>{{ $cover['date_label'] ?: $proposal->created_at?->format('F Y') ?? now()->format('F Y') }}</dd>
        </div>
    </dl>
</article>
