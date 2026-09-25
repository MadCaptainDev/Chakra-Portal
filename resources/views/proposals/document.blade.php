{{--
    The proposal as a PDF, rendered by dompdf (ProposalController::renderPdf()).

    dompdf-safe on purpose, same rules MonthlyReportDocumentRenderer learned:
    every side-by-side layout is a real <table> (no flex, no grid, no float),
    no percentage heights, fonts and images embedded as data URIs so nothing
    depends on a reachable URL or a public/storage symlink. Colours are
    literal hex -- the same values as proposal.css's tokens.

    Pages flow: a section with "new page" breaks before it, otherwise content
    runs on. The running footer and "NN / TT" are drawn by the page script in
    renderPdf(), not in HTML, so they land on every page however the content
    breaks -- and not on the cover.
--}}
@php
    use App\Support\Assets;
    use App\Support\Fonts;
    use App\Support\ProposalBlocks;

    $sections = $proposal->normalizedSections();
    $coverSection = collect($sections)->firstWhere('type', 'cover');
    $bodySections = array_values(array_filter($sections, fn ($s) => $s['type'] === 'section'));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>{{ $proposal->title }}</title>
<style>
    @font-face { font-family: 'Poppins'; font-weight: 400; font-style: normal; src: url({{ Fonts::dataUri('Poppins-Regular.ttf') }}) format('truetype'); }
    @font-face { font-family: 'Poppins'; font-weight: 600; font-style: normal; src: url({{ Fonts::dataUri('Poppins-SemiBold.ttf') }}) format('truetype'); }
    @font-face { font-family: 'Poppins'; font-weight: 700; font-style: normal; src: url({{ Fonts::dataUri('Poppins-Bold.ttf') }}) format('truetype'); }
    @font-face { font-family: 'Poppins'; font-weight: 800; font-style: normal; src: url({{ Fonts::dataUri('Poppins-ExtraBold.ttf') }}) format('truetype'); }

    @page { size: A4; margin: 0.4in 0.45in 0.65in 0.45in; }
    @if ($coverSection)
    @page :first { margin: 0; }
    @endif

    body { margin: 0; font-family: 'Poppins', sans-serif; font-size: 9.5pt; line-height: 1.15; color: #374151; }

    /* Cover */
    .cover { width: 210mm; height: 296mm; background: #132A38; color: #FFFFFF; page-break-after: always; overflow: hidden; }
    /* Three fixed-height rows rather than absolute positioning: dompdf
       placed an absolutely positioned bottom row outside the clipped page. */
    .cover-grid { width: 100%; }
    .cover-grid td.pad { padding: 0 0.5in; }
    .cover-top td { vertical-align: top; }
    .cover-eyebrow { font-size: 8pt; letter-spacing: 2.5pt; text-transform: uppercase; color: #8ACCE0; text-align: right; }
    .cover-brand { height: 36pt; }
    .cover-for { font-size: 8.5pt; letter-spacing: 2.5pt; text-transform: uppercase; color: #8ACCE0; margin-bottom: 12pt; }
    .cover-logo { height: 40pt; margin-bottom: 14pt; }
    .cover h1 { font-size: 40pt; line-height: 1.05; font-weight: 800; margin: 0 0 12pt; color: #FFFFFF; }
    .cover-sub { font-size: 17pt; line-height: 1.35; color: #E4F2F7; margin: 0; width: 120mm; }
    .cover-meta { border-top: 1px solid #3A5361; }
    .cover-meta td { padding-top: 14pt; }
    .cover-meta td { width: 33%; vertical-align: top; font-size: 9.5pt; }
    .cover-meta .k { font-size: 8pt; letter-spacing: 1pt; text-transform: uppercase; color: #9FB1BA; }
    .cover-meta .v { font-weight: 600; margin-top: 3pt; }

    /* Sections */
    .newpage { page-break-before: always; }
    .section { margin-bottom: 11pt; }
    h2 { font-size: 15pt; font-weight: 700; color: #111827; margin: 0 0 6pt; line-height: 1.3; page-break-after: avoid; }
    h2 .n { color: #4FA9C4; font-weight: 600; padding-right: 8pt; }
    h3 { font-size: 11pt; font-weight: 600; color: #111827; margin: 14pt 0 4pt; page-break-after: avoid; }
    p { margin: 0 0 7pt; }
    strong { font-weight: 600; color: #111827; }
    strong.cp-ok { color: #15803D; }
    strong.cp-warn { color: #B45309; }
    .cp-muted { color: #6B7280; }
    .cp-fine { color: #6B7280; font-size: 9pt; }
    ul, ol { margin: 0 0 9pt; padding-left: 14pt; }
    li { margin: 1.5pt 0; }
    .cp-boxed { border: 1px solid #ABDAE7; border-radius: 9pt; padding: 10pt 14pt 10pt 28pt; }

    table { border-collapse: collapse; width: 100%; }
    .cp-table-wrap { margin: 5pt 0 9pt; }
    .cp-table-wrap table { font-size: 8.5pt; line-height: 1.1; }
    .cp-table-wrap th { text-align: left; font-weight: 600; color: #111827; background: #F2F9FC; border-bottom: 1px solid #ABDAE7; padding: 4pt 6pt; font-size: 7.5pt; letter-spacing: .5pt; text-transform: uppercase; }
    .cp-table-wrap td { border-bottom: 1px solid #E5E7EB; padding: 5pt 6pt; vertical-align: top; }
    .cp-table-wrap tr { page-break-inside: avoid; }
    td.cp-strong, tr.cp-strong td { font-weight: 600; color: #111827; }
    .cp-right { text-align: right; }

    .cp-callout { border-radius: 8pt; padding: 9pt 12pt; margin: 0 0 9pt; page-break-inside: avoid; }
    .cp-callout--brand { background: #F2F9FC; color: #284250; }
    .cp-callout--gray { background: #F3F4F6; color: #374151; }
    .cp-callout--outline { border: 1px solid #E5E7EB; color: #111827; }

    .tag { padding: 1.5pt 7pt; border-radius: 8pt; font-weight: 600; font-size: 8.5pt; }
    .tag-requirement { background: #E4F2F7; color: #2F6E84; }
    .tag-recommendation { background: #FEF3C7; color: #92400E; }
    .tag-optional { background: #F3F4F6; color: #4B5563; }

    .grid { border-collapse: separate; border-spacing: 6pt; margin: 2pt 0 6pt; width: 100%; }
    .grid td { vertical-align: top; }
    .box { border: 1px solid #E5E7EB; border-radius: 8pt; padding: 9pt 11pt; }
    .label { font-size: 7.5pt; letter-spacing: 1pt; text-transform: uppercase; color: #3D8CA6; font-weight: 600; margin-bottom: 3pt; }
    .dark { background: #132A38; color: #FFFFFF; }
    .light { background: #F2F9FC; color: #284250; }
    .chip { font-weight: 600; font-size: 8.5pt; text-align: center; border-radius: 6pt; padding: 7pt 4pt; }
    .keep { page-break-inside: avoid; }
</style>
</head>
<body>

@if ($coverSection)
    @include('proposals.sections.pdf.cover', ['cover' => $coverSection['data']])
@endif

@foreach ($bodySections as $i => $section)
    @include('proposals.sections.pdf.section', [
        'data' => $section['data'],
        'breakBefore' => $i > 0 && $section['data']['new_page'],
    ])
@endforeach

</body>
</html>
