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
 */
class MonthlyReportDocumentRenderer
{
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

    // -- Page 1: hero, headline numbers, follower growth --------------------

    private function page1(Client $client, SocialAccount $account, Carbon $month, Carbon $since, Carbon $until, array $data): string
    {
        $logo = e(Assets::image('images/chakra-logo.png'));
        $clientName = e($client->name);
        $handle = e($account->handle());
        $rangeLine = $since->format('j F').' – '.$until->format('j F Y').' · figures read in Asia/Kolkata';
        $note = e($data['note'] ?? '') ?: null;

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

        return <<<HTML
<div class="page" id="p1">
    <div class="hero">
        <table class="hero-top"><tr>
            <td><img src="{$logo}" alt="Chakra Productions" class="logo"></td>
            <td class="hero-top-right">Report {$month->format('m · Y')}</td>
        </tr></table>
        <p class="eyebrow">Monthly social media report</p>
        <h1 class="client-title">{$clientName}</h1>
        <p class="sub">Instagram · {$rangeLine}</p>
        <table class="hero-row"><tr>{$heroHtml}</tr></table>
    </div>
    <div class="body">
        <p class="section-label">The month in one paragraph</p>
        {$notePara}
        <div class="chart-block">
            <div class="chart-head">
                <h2 class="h2">Follower growth, day by day</h2>
            </div>
            <div class="bars">{$bars}</div>
            <table class="bars-axis"><tr>
                <td>{$since->format('j M')}</td>
                <td class="axis-mid">{$since->copy()->addDays((int) round($since->diffInDays($until) / 2))->format('j M')}</td>
                <td class="axis-end">{$until->format('j M')}</td>
            </tr></table>
        </div>
    </div>
    <div class="footer-strip"><span>Chakra Productions · {$handle}</span><span>Page 1 of 3</span></div>
</div>
HTML;
    }

    private function growthBars(array $trend): string
    {
        $max = max(1, ...array_column($trend, 'value'));
        $html = '';

        foreach ($trend as $day) {
            $pct = max(3, (int) round($day['value'] / $max * 100));
            $isPeak = $day['value'] === $max && $max > 0;
            $fill = $isPeak ? '#132A38' : '#67BCD4';
            $html .= '<div class="bar-col"><div class="bar" style="height:'.$pct.'%;background:'.$fill.';"></div></div>';
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
            $caption = e($item->shortCaption(70));
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

        return <<<HTML
<div class="page" id="p2">
    <div class="page-head"><span>{$clientName}</span><span class="page-head-right">How the month performed</span></div>
    <div class="body">
        <div class="chart-block">
            <div class="chart-head">
                <h2 class="h2">Engagement breakdown</h2>
                <p class="chart-sub">{$totalEngagement} interactions in total</p>
            </div>
            {$breakdownHtml}
        </div>
        <div class="chart-block">
            <div class="chart-head">
                <h2 class="h2">The posts that worked hardest</h2>
                <p class="chart-sub">Ranked by accounts reached</p>
            </div>
            <table class="posts-table">
                <thead><tr><th>Content</th><th>Type</th><th class="num">Reach</th><th class="num">Views</th><th class="num">Engagement</th></tr></thead>
                <tbody>{$rows}</tbody>
            </table>
        </div>
    </div>
    <div class="footer-strip"><span>Chakra Productions · Digital Chaos</span><span>Page 2 of 3</span></div>
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
<div class="audience-grid">
    <div><p class="mini-label">Age, %</p>{$ageHtml}</div>
    <div><p class="mini-label">Gender, %</p>{$genderHtml}</div>
    <div><p class="mini-label">Top cities</p>{$cityHtml}</div>
</div>
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
            ? '<div class="chart-block"><div class="chart-head"><h2 class="h2">Shoots this month</h2></div><table class="shoots-table">'.$shootRows.'</table></div>'
            : '';

        return <<<HTML
<div class="page" id="p3">
    <div class="page-head"><span>{$clientName}</span><span class="page-head-right">Audience and production</span></div>
    <div class="body">
        <div class="chart-block">
            <div class="chart-head">
                <h2 class="h2">Who is following</h2>
                <p class="chart-sub">{$followers} followers at the close of the month.</p>
            </div>
            {$audienceBlock}
        </div>
        <div class="chart-block">
            <div class="chart-head"><h2 class="h2">What we published</h2></div>
            <table class="formats-row"><tr>{$formatsHtml}</tr></table>
        </div>
        {$shootsBlock}
    </div>
    <div class="footer-band"><p>Anything on these three pages is a question for the studio — write to us and we will sort it out.</p><span>Page 3 of 3</span></div>
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
        $html = '<div class="bar-list">';

        foreach ($items as $item) {
            $pct = max(2, (int) round($item['value'] / $max * 100));
            $html .= '<div class="bar-list-row"><div class="bar-list-head"><span>'.e($item['label']).'</span>'
                .'<span class="bar-list-value">'.e(number_format($item['value'])).$suffix.'</span></div>'
                .'<div class="bar-list-track"><div class="bar-list-fill" style="width:'.$pct.'%;"></div></div></div>';
        }

        return $html.'</div>';
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

.hero { background: #132A38; color: #fff; padding: 12mm 12mm 8mm; }
.hero-top { width: 100%; border-collapse: collapse; }
.hero-top td { padding: 0; vertical-align: top; }
.logo { height: 9mm; }
.hero-top-right { text-align: right; font-size: 9pt; font-weight: 600; letter-spacing: 1px; text-transform: uppercase; color: rgba(228,242,247,.5); }
.eyebrow { margin: 9mm 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase; letter-spacing: 2px; color: #8ACCE0; }
.client-title { margin: 3mm 0 0; font-size: 27pt; font-weight: 800; line-height: 1.05; color: #fff; }
.sub { margin: 3mm 0 0; font-size: 10.5pt; color: rgba(228,242,247,.7); }
.hero-row { width: 100%; border-collapse: collapse; margin-top: 8mm; border-top: 1px solid rgba(255,255,255,.14); }
.hero-cell { padding: 4mm 3mm 0; border-left: 1px solid rgba(255,255,255,.14); width: 20%; }
.hero-value { margin: 0; font-size: 16pt; font-weight: 800; line-height: 1; }
.hero-label { margin: 2mm 0 0; font-size: 7.5pt; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: rgba(228,242,247,.6); }

.body { padding: 8mm 12mm 0; }
.page-head { padding: 7mm 12mm 3mm; border-bottom: 1px solid #E5E7EB; font-size: 10pt; font-weight: 600; }
.page-head-right { float: right; font-size: 9pt; text-transform: uppercase; letter-spacing: 1px; color: #9CA3AF; }
.section-label { margin: 0 0 3mm; font-size: 9pt; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: #3D8CA6; }
.note-text { margin: 0; font-size: 10.5pt; line-height: 1.6; color: #374151; max-width: 150mm; }
.note-empty { color: #9CA3AF; font-style: italic; }

.chart-block { margin-top: 8mm; }
.chart-head { border-top: 2px solid #111827; padding-top: 3mm; margin-bottom: 4mm; }
.h2 { margin: 0; font-size: 13pt; font-weight: 700; display: inline; }
.chart-sub { margin: 0; font-size: 9pt; color: #6B7280; float: right; }

.bars { height: 40mm; display: table; table-layout: fixed; width: 100%; }
.bar-col { display: table-cell; vertical-align: bottom; padding: 0 0.3mm; }
.bar { width: 100%; border-radius: 1px 1px 0 0; }
.bars-axis { width: 100%; border-top: 1px solid #D1D5DB; margin-top: 2mm; font-size: 8pt; color: #9CA3AF; }
.bars-axis td { padding-top: 1.5mm; }
.axis-mid { text-align: center; }
.axis-end { text-align: right; }

.bar-list-row { margin-bottom: 3mm; }
.bar-list-head { font-size: 9.5pt; }
.bar-list-value { float: right; font-weight: 600; }
.bar-list-track { height: 2.2mm; border-radius: 2mm; background: #F3F4F6; margin-top: 1.5mm; overflow: hidden; }
.bar-list-fill { height: 100%; background: #3D8CA6; border-radius: 2mm; }

.posts-table { width: 100%; border-collapse: collapse; }
.posts-table th { text-align: left; padding: 2mm 0; font-size: 8pt; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: #6B7280; border-bottom: 1px solid #D1D5DB; }
.posts-table td { padding: 2.5mm 0; border-bottom: 1px solid #F3F4F6; font-size: 9.5pt; vertical-align: top; }
.post-cell { max-width: 70mm; }
.post-rank { font-weight: 700; color: #67BCD4; margin-right: 3mm; }
.post-caption { font-weight: 500; }
.post-date { font-size: 8pt; color: #9CA3AF; font-weight: 400; }
.badge { display: inline-block; padding: 0.8mm 2.5mm; border-radius: 3mm; font-size: 7.5pt; font-weight: 600; }
.num { text-align: right; font-weight: 600; }
.num-muted { color: #4B5563; font-weight: 400; }
.empty-row { color: #9CA3AF; font-size: 9.5pt; padding: 4mm 0; }

.audience-grid { display: table; table-layout: fixed; width: 100%; }
.audience-grid > div { display: table-cell; vertical-align: top; width: 33.33%; padding-right: 6mm; }
.mini-label { margin: 0 0 3mm; font-size: 8pt; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: #6B7280; }

.formats-row { width: 100%; border-collapse: collapse; }
.format-cell { padding: 0 4mm 0 0; border-left: 1px solid #E5E7EB; width: 25%; }
.format-cell:first-child { border-left: none; padding-left: 0; }
.format-count { margin: 0; font-size: 15pt; font-weight: 800; color: #2F6E84; }
.format-label { margin: 1.5mm 0 0; font-size: 8pt; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: #6B7280; }

.shoots-table { width: 100%; border-collapse: collapse; }
.shoots-table td { padding: 2.5mm 0; border-bottom: 1px solid #F3F4F6; vertical-align: top; }
.shoot-date { width: 18mm; font-size: 9pt; font-weight: 600; color: #3D8CA6; }
.shoot-title { margin: 0; font-size: 9.5pt; font-weight: 500; }
.shoot-meta { margin: 1mm 0 0; font-size: 8pt; color: #6B7280; }

.footer-strip { position: absolute; bottom: 0; left: 0; width: 210mm; padding: 5mm 12mm; display: table; table-layout: fixed; box-sizing: border-box; border-top: 1px solid #E5E7EB; font-size: 8pt; color: #9CA3AF; }
.footer-strip span { display: table-cell; }
.footer-strip span:last-child { text-align: right; }
.footer-band { position: absolute; bottom: 0; left: 0; width: 210mm; box-sizing: border-box; background: #132A38; color: rgba(228,242,247,.7); padding: 6mm 12mm; }
.footer-band p { margin: 0; font-size: 9pt; line-height: 1.5; max-width: 140mm; display: inline-block; }
.footer-band span { float: right; font-size: 8pt; text-transform: uppercase; letter-spacing: 1px; }
CSS;
    }
}
