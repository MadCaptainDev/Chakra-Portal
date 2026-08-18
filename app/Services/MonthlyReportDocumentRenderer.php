<?php

namespace App\Services;

use App\Models\Client;
use App\Models\SocialAccount;
use App\Models\SocialMediaItem;
use App\Support\Assets;
use App\Support\Fonts;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Renders the 3-page A4 monthly report as one standalone HTML document, fed
 * straight to dompdf (see MonthlyReportController::pdf()).
 *
 * Mirrors InvoiceDocumentRenderer's approach, not the reflowing
 * window.print() pattern used elsewhere in the app: own document, no
 * Tailwind, no app layout, fonts and the studio logo embedded as base64
 * data URIs (Fonts::dataUri()/Assets::image()) so nothing depends on a
 * public/storage symlink -- this host has symlink() disabled. Three fixed
 * .page divs with page-break-after: always reproduce the design's exact
 * 3-page layout; @page { size: A4; margin: 0 } and explicit 210mm/297mm
 * page boxes are the same geometry InvoiceDocumentRenderer already proves
 * out on this host.
 *
 * Every left/right layout here is a real <table>, and every bar height is
 * computed in PHP as an explicit mm figure -- not `float` and not a
 * percentage height inside a table-cell. Both looked fine on paper but
 * rendered wrong: dompdf's float support is unreliable (confirmed against
 * InvoiceDocumentRenderer, which uses table.header/td for every left/right
 * split in this codebase's one other dompdf document and never floats
 * anything), and a percentage height only resolves against an ancestor
 * with a DEFINITE height, which a table-cell's auto-derived row height is
 * not, in dompdf specifically -- the growth chart's bars were invisible
 * because of exactly this.
 */
class MonthlyReportDocumentRenderer
{
    /** The growth chart's fixed height, referenced by both the CSS and the PHP that computes each bar's own height against it. */
    private const CHART_HEIGHT_MM = 40;

    public function render(Client $client, SocialAccount $account, Carbon $month): string
    {
        [$since, $until] = MonthlyReportData::monthRange($month);
        $data = MonthlyReportData::forRange($client, $account, $since, $until);

        $page1 = $this->page1($client, $account, $month, $since, $until, $data);
        $page2 = $this->page2($client, $data);
        $page3 = $this->page3($client, $account, $since, $until, $data);

        $title = e($client->name.' — '.$month->format('F Y').' report');

        $css = $this->baseCss();

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>{$title}</title>
<style>{$css}</style>
</head>
<body>
{$page1}
{$page2}
{$page3}
</body>
</html>
HTML;
    }

    /** A table row with the left cell's content on the left and the right cell's on the right -- dompdf's proven way to do this, not float. */
    private function splitRow(string $left, string $right, string $class = ''): string
    {
        $classAttr = $class !== '' ? ' class="'.$class.'"' : '';

        return '<table'.$classAttr.' width="100%" cellspacing="0" cellpadding="0"><tr>'
            .'<td class="split-left">'.$left.'</td>'
            .'<td class="split-right">'.$right.'</td>'
            .'</tr></table>';
    }

    // -- Page 1: hero, headline numbers, follower growth --------------------

    private function page1(Client $client, SocialAccount $account, Carbon $month, Carbon $since, Carbon $until, array $data): string
    {
        $logo = e(Assets::image('images/chakra-logo.png'));
        $clientName = e($client->name);
        $handle = e($account->handle());
        $rangeLine = $since->format('j F').' – '.$until->format('j F Y').' · figures read in Asia/Kolkata';
        $note = e($this->stripEmoji((string) ($data['note'] ?? ''))) ?: null;

        $hero = [
            ['value' => number_format((int) $data['overview']['followers']), 'label' => 'Followers'],
            ['value' => $this->signed($data['overview']['follower_growth']), 'label' => 'Net growth'],
            ['value' => number_format($data['overview']['reach']), 'label' => 'Accounts reached'],
            ['value' => number_format($data['overview']['engagement']), 'label' => 'Interactions'],
            ['value' => number_format($data['content']->count()), 'label' => 'Pieces published'],
        ];

        $heroHtml = '';
        foreach ($hero as $item) {
            $heroHtml .= '<td class="hero-cell"><p class="hero-value">'.e($item['value']).'</p>'
                .'<p class="hero-label">'.e($item['label']).'</p></td>';
        }

        $bars = $this->growthBars($data['trend']);
        $notePara = $note
            ? '<p class="note-text">'.nl2br(e($note)).'</p>'
            : '<p class="note-text note-empty">No note written for this month yet.</p>';

        $topBar = $this->splitRow(
            '<img src="'.$logo.'" alt="Chakra Productions" class="logo">',
            'Report '.$month->format('m · Y'),
            'hero-top',
        );

        $chartHead = $this->splitRow(
            '<span class="h2">Follower growth, day by day</span>', '', 'chart-head',
        );

        $footer = '<div class="footer-strip">'.$this->splitRow('Chakra Productions · '.$handle, 'Page 1 of 3').'</div>';

        return <<<HTML
<div class="page" id="p1">
    <div class="hero">
        {$topBar}
        <p class="eyebrow">Monthly social media report</p>
        <h1 class="client-title">{$clientName}</h1>
        <p class="sub">Instagram · {$rangeLine}</p>
        <table class="hero-row" width="100%" cellspacing="0" cellpadding="0"><tr>{$heroHtml}</tr></table>
    </div>
    <div class="body">
        <p class="section-label">The month in one paragraph</p>
        {$notePara}
        <div class="chart-block">
            {$chartHead}
            <table class="bars" width="100%" cellspacing="0" cellpadding="0"><tr>{$bars}</tr></table>
            <table class="bars-axis" width="100%" cellspacing="0" cellpadding="0"><tr>
                <td>{$since->format('j M')}</td>
                <td class="axis-mid">{$since->copy()->addDays((int) round($since->diffInDays($until) / 2))->format('j M')}</td>
                <td class="axis-end">{$until->format('j M')}</td>
            </tr></table>
        </div>
    </div>
    {$footer}
</div>
HTML;
    }

    /**
     * One <td> per day, each holding a bar whose HEIGHT is an explicit mm
     * figure computed here in PHP -- not a CSS percentage. A percentage
     * height only resolves against an ancestor with a definite height, and
     * a table-cell's height (derived from its row, which is derived from
     * its tallest cell's content) is not one in dompdf: every bar rendered
     * at zero height until this was computed server-side instead.
     */
    private function growthBars(array $trend): string
    {
        $max = max(1, ...array_column($trend, 'value'));
        $html = '';

        foreach ($trend as $day) {
            $heightMm = max(1, round($day['value'] / $max * self::CHART_HEIGHT_MM, 1));
            $spacerMm = round(self::CHART_HEIGHT_MM - $heightMm, 1);
            $isPeak = $day['value'] === $max && $max > 0;
            $fill = $isPeak ? '#132A38' : '#67BCD4';

            // The empty space above the bar is a real spacer div, not
            // vertical-align: bottom on the cell -- dompdf's table-cell
            // vertical-align support is inconsistent enough that an
            // explicit spacer is the safer bet, same reasoning as the bar
            // height itself.
            $html .= '<td class="bar-col">'
                .'<div class="bar-spacer" style="height:'.$spacerMm.'mm;"></div>'
                .'<div class="bar" style="height:'.$heightMm.'mm;background:'.$fill.';"></div>'
                .'</td>';
        }

        return $html;
    }

    // -- Page 2: engagement breakdown, top posts -----------------------------

    private function page2(Client $client, array $data): string
    {
        $clientName = e($client->name);
        $breakdownHtml = $this->barList($data['breakdown']);
        $totalEngagement = number_format($data['overview']['engagement']);

        $rows = '';
        $rank = 1;

        foreach ($data['content']->take(5) as $item) {
            /** @var SocialMediaItem $item */
            [$bg, $fg] = $this->typeColors($item);
            $caption = e($this->stripEmoji($item->shortCaption(70)));
            $date = e($item->posted_at?->format('j M Y') ?? '');
            $reach = $item->metricValue('reach') !== null ? number_format($item->metricValue('reach')) : '—';
            $views = $item->metricValue('views') !== null ? number_format($item->metricValue('views')) : '—';
            $eng = $item->metricValue('total_interactions') !== null ? number_format($item->metricValue('total_interactions')) : '—';
            $type = e($item->typeLabel());

            $rows .= <<<HTML
<tr>
    <td class="post-cell">
        <span class="post-rank">{$rank}</span>
        <span class="post-caption">{$caption}<br><span class="post-date">{$date}</span></span>
    </td>
    <td class="post-type"><span class="badge" style="background:{$bg};color:{$fg};">{$type}</span></td>
    <td class="num">{$reach}</td>
    <td class="num num-muted">{$views}</td>
    <td class="num">{$eng}</td>
</tr>
HTML;
            $rank++;
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="5" class="empty-row">Nothing was posted in this range.</td></tr>';
        }

        $pageHead = $this->splitRow($clientName, 'How the month performed', 'page-head');
        $breakdownHead = $this->splitRow('<span class="h2">Engagement breakdown</span>', $totalEngagement.' interactions in total', 'chart-head');
        $postsHead = $this->splitRow('<span class="h2">The posts that worked hardest</span>', 'Ranked by accounts reached', 'chart-head');
        $footer = '<div class="footer-strip">'.$this->splitRow('Chakra Productions · Digital Chaos', 'Page 2 of 3').'</div>';

        return <<<HTML
<div class="page" id="p2">
    {$pageHead}
    <div class="body">
        <div class="chart-block">
            {$breakdownHead}
            {$breakdownHtml}
        </div>
        <div class="chart-block">
            {$postsHead}
            <table class="posts-table" width="100%" cellspacing="0" cellpadding="0">
                <thead><tr><th>Content</th><th>Type</th><th class="num">Reach</th><th class="num">Views</th><th class="num">Engagement</th></tr></thead>
                <tbody>{$rows}</tbody>
            </table>
        </div>
    </div>
    {$footer}
</div>
HTML;
    }

    // -- Page 3: audience, publishing, shoots --------------------------------

    private function page3(Client $client, SocialAccount $account, Carbon $since, Carbon $until, array $data): string
    {
        $clientName = e($client->name);
        $followers = number_format((int) $data['overview']['followers']);

        if ($data['audienceSyncedAt']) {
            $ageHtml = $this->barList($data['ageBreakdown'], suffix: '%');
            $genderHtml = $this->barList($data['genderBreakdown'], suffix: '%');
            $cityHtml = $this->barList($data['topCities']);
            $audienceBlock = <<<HTML
<table class="audience-grid" width="100%" cellspacing="0" cellpadding="0"><tr>
    <td class="audience-col"><p class="mini-label">Age, %</p>{$ageHtml}</td>
    <td class="audience-col"><p class="mini-label">Gender, %</p>{$genderHtml}</td>
    <td class="audience-col audience-col-last"><p class="mini-label">Top cities</p>{$cityHtml}</td>
</tr></table>
HTML;
        } else {
            $audienceBlock = '<p class="empty-row">Audience demographics have not been synced for this account yet.</p>';
        }

        $formatsHtml = '';
        foreach ($data['formats'] as $format) {
            $formatsHtml .= '<td class="format-cell"><p class="format-count">'.e((string) $format['count']).'</p>'
                .'<p class="format-label">'.e($format['label']).'s</p></td>';
        }
        if ($formatsHtml === '') {
            $formatsHtml = '<td class="format-cell"><p class="empty-row">Nothing published this range.</p></td>';
        }

        $shootRows = '';
        foreach ($data['shoots'] as $shoot) {
            $date = e($shoot->starts_at?->format('j M') ?? '');
            $title = e($shoot->title);
            $meta = e(trim(($shoot->location ?? '').($shoot->location ? ' · ' : '').ucfirst($shoot->status)));
            $shootRows .= <<<HTML
<tr><td class="shoot-date">{$date}</td><td><p class="shoot-title">{$title}</p><p class="shoot-meta">{$meta}</p></td></tr>
HTML;
        }

        $shootsBlock = $shootRows !== ''
            ? '<div class="chart-block"><h2 class="h2 h2-block">Shoots this month</h2>'
                .'<table class="shoots-table" width="100%" cellspacing="0" cellpadding="0">'.$shootRows.'</table></div>'
            : '';

        $pageHead = $this->splitRow($clientName, 'Audience and production', 'page-head');
        $audienceHead = $this->splitRow('<span class="h2">Who is following</span>', $followers.' followers at the close of the month.', 'chart-head');
        $footer = '<div class="footer-band">'.$this->splitRow(
            '<span class="footer-band-text">Anything on these three pages is a question for the studio — write to us and we will sort it out.</span>',
            'Page 3 of 3',
        ).'</div>';

        return <<<HTML
<div class="page" id="p3">
    {$pageHead}
    <div class="body">
        <div class="chart-block">
            {$audienceHead}
            {$audienceBlock}
        </div>
        <div class="chart-block">
            <h2 class="h2 h2-block">What we published</h2>
            <table class="formats-row" width="100%" cellspacing="0" cellpadding="0"><tr>{$formatsHtml}</tr></table>
        </div>
        {$shootsBlock}
    </div>
    {$footer}
</div>
HTML;
    }

    /**
     * @param  list<array{label: string, value: int}>  $items
     */
    private function barList(array $items, string $suffix = ''): string
    {
        if ($items === []) {
            return '<p class="empty-row">Nothing to show.</p>';
        }

        $max = max(1, ...array_column($items, 'value'));
        $rows = '';

        foreach ($items as $item) {
            $pct = max(2, (int) round($item['value'] / $max * 100));
            $head = $this->splitRow(e($item['label']), e(number_format($item['value'])).$suffix, 'bar-list-head');
            $rows .= '<div class="bar-list-row">'.$head
                .'<div class="bar-list-track"><div class="bar-list-fill" style="width:'.$pct.'%;"></div></div></div>';
        }

        return '<div class="bar-list">'.$rows.'</div>';
    }

    /**
     * Instagram captions and staff notes routinely contain emoji; the
     * embedded Poppins font file has no emoji glyphs, and confirmed against
     * a real rendered PDF: dompdf's fallback for a missing glyph is not to
     * skip the one character, it silently swaps the entire surrounding text
     * run to a default serif font, which reads as a rendering bug on every
     * caption that happens to contain one. Stripping emoji (and other
     * symbol/pictograph ranges) before rendering keeps captions in the
     * studio's own typeface; the emoji itself was never load-bearing
     * information here.
     */
    private function stripEmoji(string $text): string
    {
        $stripped = preg_replace(
            '/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{2190}-\x{21FF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}]/u',
            '',
            $text,
        ) ?? $text;

        return trim(preg_replace('/\s{2,}/', ' ', $stripped) ?? $stripped);
    }

    private function signed(?int $value): string
    {
        if ($value === null) {
            return '—';
        }

        return ($value >= 0 ? '+' : '').number_format($value);
    }

    /**
     * @return array{0: string, 1: string} [background, foreground]
     */
    private function typeColors(SocialMediaItem $item): array
    {
        return match (true) {
            $item->isReel() => ['#F3E8FF', '#6B21A8'],
            $item->media_type === SocialMediaItem::TYPE_CAROUSEL => ['#CCFBF1', '#115E59'],
            default => ['#F3F4F6', '#4B5563'],
        };
    }

    private function baseCss(): string
    {
        $poppinsRegular = Fonts::dataUri('Poppins-Regular.ttf');
        $poppinsSemiBold = Fonts::dataUri('Poppins-SemiBold.ttf');
        $poppinsBold = Fonts::dataUri('Poppins-Bold.ttf');
        $poppinsExtraBold = Fonts::dataUri('Poppins-ExtraBold.ttf');
        $chartHeight = self::CHART_HEIGHT_MM;

        return <<<CSS
@font-face { font-family: 'Poppins'; font-weight: 400; src: url({$poppinsRegular}) format('truetype'); }
@font-face { font-family: 'Poppins'; font-weight: 600; src: url({$poppinsSemiBold}) format('truetype'); }
@font-face { font-family: 'Poppins'; font-weight: 700; src: url({$poppinsBold}) format('truetype'); }
@font-face { font-family: 'Poppins'; font-weight: 800; src: url({$poppinsExtraBold}) format('truetype'); }

@page { size: A4; margin: 0; }
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; font-family: 'Poppins', Arial, sans-serif; color: #111827; background: #fff; }
.page { position: relative; width: 210mm; height: 297mm; overflow: hidden; page-break-after: always; }
.page:last-child { page-break-after: auto; }

/* Every left/right layout in this document is this one table, not float --
   dompdf's float support is unreliable; a table cell is not. */
.split-left { text-align: left; }
.split-right { text-align: right; }

.hero { background: #132A38; color: #fff; padding: 12mm 12mm 8mm; }
.hero-top .split-right { font-size: 9pt; font-weight: 600; letter-spacing: 1px; text-transform: uppercase; color: rgba(228,242,247,.5); vertical-align: top; }
.logo { height: 9mm; }
.eyebrow { margin: 9mm 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase; letter-spacing: 2px; color: #8ACCE0; }
.client-title { margin: 3mm 0 0; font-size: 27pt; font-weight: 800; line-height: 1.05; color: #fff; }
.sub { margin: 3mm 0 0; font-size: 10.5pt; color: rgba(228,242,247,.7); }
.hero-row { margin-top: 8mm; border-top: 1px solid rgba(255,255,255,.14); }
.hero-cell { padding: 4mm 3mm 0; border-left: 1px solid rgba(255,255,255,.14); width: 20%; }
.hero-value { margin: 0; font-size: 16pt; font-weight: 800; line-height: 1; }
.hero-label { margin: 2mm 0 0; font-size: 7.5pt; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: rgba(228,242,247,.6); }

.body { padding: 8mm 12mm 0; }
.page-head { padding: 7mm 12mm 3mm; border-bottom: 1px solid #E5E7EB; }
.page-head .split-left { font-size: 10pt; font-weight: 600; }
.page-head .split-right { font-size: 9pt; text-transform: uppercase; letter-spacing: 1px; color: #9CA3AF; vertical-align: bottom; }
.section-label { margin: 0 0 3mm; font-size: 9pt; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: #3D8CA6; }
.note-text { margin: 0; font-size: 10.5pt; line-height: 1.6; color: #374151; max-width: 150mm; }
.note-empty { color: #9CA3AF; font-style: italic; }

.chart-block { margin-top: 8mm; }
.chart-head { border-top: 2px solid #111827; padding-top: 3mm; margin-bottom: 4mm; }
.chart-head .split-right { font-size: 9pt; color: #6B7280; vertical-align: bottom; }
.h2 { font-size: 13pt; font-weight: 700; }
.h2-block { display: block; margin: 0 0 4mm; border-top: 2px solid #111827; padding-top: 3mm; }

.bars { height: {$chartHeight}mm; table-layout: fixed; }
.bar-col { vertical-align: bottom; padding: 0 0.3mm; width: 3%; }
.bar-spacer { width: 100%; }
.bar { width: 100%; border-radius: 1px 1px 0 0; }
.bars-axis { border-top: 1px solid #D1D5DB; margin-top: 2mm; font-size: 8pt; color: #9CA3AF; }
.bars-axis td { padding-top: 1.5mm; }
.axis-mid { text-align: center; }
.axis-end { text-align: right; }

.bar-list-row { margin-bottom: 3mm; }
.bar-list-head .split-left, .bar-list-head .split-right { font-size: 9.5pt; }
.bar-list-head .split-right { font-weight: 600; }
.bar-list-track { height: 2.2mm; border-radius: 2mm; background: #F3F4F6; margin-top: 1.5mm; overflow: hidden; }
.bar-list-fill { height: 100%; background: #3D8CA6; border-radius: 2mm; }

.posts-table { border-collapse: collapse; }
.posts-table th { text-align: left; padding: 2mm 0; font-size: 8pt; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: #6B7280; border-bottom: 1px solid #D1D5DB; }
.posts-table td { padding: 2.5mm 0; border-bottom: 1px solid #F3F4F6; font-size: 9.5pt; vertical-align: top; }
.post-cell { max-width: 70mm; }
.post-rank { font-weight: 700; color: #67BCD4; margin-right: 3mm; }
.post-caption { font-weight: 600; }
.post-date { font-size: 8pt; color: #9CA3AF; font-weight: 400; }
.badge { display: inline-block; padding: 0.8mm 2.5mm; border-radius: 3mm; font-size: 7.5pt; font-weight: 600; }
.num { text-align: right; font-weight: 600; }
.num-muted { color: #4B5563; font-weight: 400; }
.empty-row { color: #9CA3AF; font-size: 9.5pt; padding: 4mm 0; }

.audience-grid { table-layout: fixed; }
.audience-col { vertical-align: top; width: 33.33%; padding-right: 6mm; }
.audience-col-last { padding-right: 0; }
.mini-label { margin: 0 0 3mm; font-size: 8pt; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: #6B7280; }

.formats-row { border-collapse: collapse; }
.format-cell { padding: 0 4mm; border-left: 1px solid #E5E7EB; width: 25%; }
.format-cell:first-child { border-left: none; padding-left: 0; }
.format-count { margin: 0; font-size: 15pt; font-weight: 800; color: #2F6E84; }
.format-label { margin: 1.5mm 0 0; font-size: 8pt; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: #6B7280; }

.shoots-table { border-collapse: collapse; }
.shoots-table td { padding: 2.5mm 0; border-bottom: 1px solid #F3F4F6; vertical-align: top; }
.shoot-date { width: 18mm; font-size: 9pt; font-weight: 600; color: #3D8CA6; }
.shoot-title { margin: 0; font-size: 9.5pt; font-weight: 600; }
.shoot-meta { margin: 1mm 0 0; font-size: 8pt; color: #6B7280; }

/* Plain <div>s, not the <table> itself -- dompdf's `position: absolute`
   support on a table element is unreliable (confirmed: the footer simply
   did not render at all with position set on the table), a div is not.
   Vertical padding only on the div itself, and an explicit content width
   on the inner table rather than width + horizontal padding +
   box-sizing: border-box -- confirmed that combination is NOT honoured on
   an absolutely positioned element (the right-aligned "Page X of 3" text
   overflowed straight past the page edge). Same reasoning
   InvoiceDocumentRenderer's own position: fixed footer already uses:
   .signature there is width: 196mm (210mm minus its 14mm inset), not
   210mm-with-padding. 186mm = 210mm page minus a 12mm inset each side. */
.footer-strip { position: absolute; bottom: 0; left: 0; width: 210mm; padding: 5mm 0; border-top: 1px solid #E5E7EB; }
.footer-strip table { width: 186mm; margin: 0 12mm; }
.footer-strip .split-left, .footer-strip .split-right { font-size: 8pt; color: #9CA3AF; }
.footer-band { position: absolute; bottom: 0; left: 0; width: 210mm; background: #132A38; color: rgba(228,242,247,.7); padding: 6mm 0; }
.footer-band table { width: 186mm; margin: 0 12mm; }
.footer-band .split-left { font-size: 9pt; line-height: 1.5; }
.footer-band .split-right { font-size: 8pt; text-transform: uppercase; letter-spacing: 1px; vertical-align: bottom; }
CSS;
    }
}
