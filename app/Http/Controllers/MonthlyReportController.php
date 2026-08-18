<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\MonthlyReportNote;
use App\Models\SocialAccount;
use App\Services\Instagram\InstagramSyncRunner;
use App\Services\MonthlyReportData;
use App\Services\MonthlyReportDocumentRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * The monthly Instagram report for one client -- studio-only screen with a
 * Studio/Client on-screen preview toggle, and a downloadable PDF built from
 * the same month's data.
 *
 * Mostly reads local caches -- "Sync now" on the Instagram Insights screen
 * is the deliberate way to refresh them. show() also calls
 * InstagramSyncRunner::ensureFresh() first, same as the Insights screen:
 * a never-synced account gets its first 90 days backfilled automatically,
 * a specific never-fetched month gets filled in on demand -- see
 * InstagramSyncRunner for the full reasoning. The PDF (pdf(), below) does
 * not call it -- generating a report is assumed to happen after the screen
 * has already been opened and is current. See MonthlyReportData for
 * exactly what is gathered, shared verbatim with the PDF renderer.
 */
class MonthlyReportController extends Controller
{
    public function show(Request $request, Client $client): View
    {
        $account = $this->instagramFor($client);
        $month = $this->resolveMonth($request);
        [$since, $until] = MonthlyReportData::monthRange($month);

        if (! $account) {
            return view('instagram.report', [
                'client' => $client,
                'account' => null,
                'month' => $month,
                'since' => $since,
                'until' => $until,
            ]);
        }

        // checkWindow: false -- this screen only ever gets the first-time
        // 90-day backfill (below); auto-syncing a specific already-synced
        // account's missing month on every view was not asked for and adds
        // an API round trip to a screen that wasn't in scope for it. The
        // Insights screen's custom date picker is where "fetch again if
        // there's no data for the dates I chose" was actually requested.
        InstagramSyncRunner::ensureFresh($account, $since, $until, checkWindow: false);

        return view('instagram.report', [
            'client' => $client,
            'account' => $account,
            'month' => $month,
            'since' => $since,
            'until' => $until,
            'note' => MonthlyReportNote::forClientAndMonth($client, $month),
        ] + MonthlyReportData::forRange($client, $account, $since, $until));
    }

    /**
     * Saves the studio-authored note for one client's month. Studio-only
     * (module:clients,edit) -- there is no client-facing route at all; the
     * client's copy of the note only ever reaches them inside the
     * downloaded PDF.
     */
    public function updateNote(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        $month = $this->parseMonth($data['month']);

        MonthlyReportNote::forClientAndMonth($client, $month)
            ->forceFill([
                'note' => $data['note'] !== '' ? $data['note'] : null,
                'updated_by_id' => $request->user()->id,
            ])
            ->save();

        return redirect()->route('instagram.report', ['client' => $client, 'month' => $data['month']])
            ->with('status', 'Note saved.');
    }

    public function pdf(Request $request, Client $client, MonthlyReportDocumentRenderer $renderer): Response
    {
        $account = $this->instagramFor($client);

        abort_unless($account, 404);

        $month = $this->resolveMonth($request);

        $html = $renderer->render($client, $account, $month);
        $filename = $client->name.' — '.$month->format('F Y').' report.pdf';

        return Pdf::loadHTML($html)->setPaper('a4')->download($filename);
    }

    /**
     * The month this report covers. A monthly report is written after the
     * month closes -- defaults to the previous full calendar month, not the
     * one still in progress.
     */
    private function resolveMonth(Request $request): Carbon
    {
        $raw = (string) $request->query('month', '');

        if ($raw !== '') {
            try {
                return $this->parseMonth($raw);
            } catch (\Throwable) {
                // An unparsable month falls back rather than 500ing on a
                // typo in the address bar.
            }
        }

        return now()->subMonthNoOverflow()->startOfMonth();
    }

    /**
     * "Y-m" -> the first of that month.
     *
     * NOT Carbon::createFromFormat('Y-m', $raw)->startOfMonth(): a "Y-m"
     * format string carries no day, so Carbon fills in TODAY's day of month
     * -- parsing "2026-02" on the 31st produces "2026-02-31", which Carbon
     * silently overflows to 3 March, and startOfMonth() on that gives 1
     * March, the wrong month entirely. Appending an explicit "-01" avoids
     * the overflow before it can happen.
     */
    private function parseMonth(string $raw): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $raw.'-01')->startOfDay();
    }

    private function instagramFor(Client $client): ?SocialAccount
    {
        return $client->socialAccounts()
            ->forPlatform(SocialAccount::PLATFORM_INSTAGRAM)
            ->connected()
            ->first();
    }
}
