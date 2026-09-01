<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ResolvesClient;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\MonthlyReportNote;
use App\Models\SocialAccount;
use App\Services\Instagram\InstagramSyncRunner;
use App\Services\MonthlyReportData;
use App\Services\MonthlyReportDocumentRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Your own monthly Instagram report, self-service -- read-only, the same
 * screen and PDF as the staff-side
 * App\Http\Controllers\MonthlyReportController, without the studio's own
 * controls (which sections are included, the private note, sending it
 * elsewhere over WhatsApp). $selfService on the shared instagram.report view
 * strips all of that chrome rather than merely hiding it, so none of that
 * markup -- or the routes it would post to, none of which this role could
 * reach anyway -- ships to a client's page at all.
 *
 * Which sections show is never something a client chooses here: always
 * Client::defaultReportSections(), the studio's own standing editorial
 * decision for this client, with no per-visit override the way the staff
 * screen's checklist offers itself. See MonthlyReportController::
 * resolveSections() for the override this deliberately does not carry.
 */
class MonthlyReportController extends Controller
{
    use ResolvesClient;

    public function show(Request $request): View
    {
        $client = $this->client($request);
        $account = $this->instagramFor($client);
        $month = $this->resolveMonth($request);
        [$since, $until] = MonthlyReportData::monthRange($month);
        $enabledSections = $client->defaultReportSections();

        $shared = [
            'client' => $client,
            'selfService' => true,
            'reportRouteName' => 'client.instagram.report',
            'monthNavParams' => [],
            'reportPdfUrl' => route('client.instagram.report.pdf', ['month' => $month->format('Y-m')]),
            'backUrl' => route('client.social'),
            'month' => $month,
            'since' => $since,
            'until' => $until,
            'enabledSections' => $enabledSections,
        ];

        if (! $account) {
            return view('instagram.report', $shared + ['account' => null]);
        }

        InstagramSyncRunner::ensureFresh($account, $since, $until, checkWindow: false);

        return view('instagram.report', $shared + [
            'account' => $account,
            'note' => MonthlyReportNote::forClientAndMonth($client, $month),
        ] + MonthlyReportData::forRange($client, $account, $since, $until));
    }

    public function pdf(Request $request, MonthlyReportDocumentRenderer $renderer): Response
    {
        $client = $this->client($request);
        $account = $this->instagramFor($client);

        abort_unless($account, 404);

        $month = $this->resolveMonth($request);
        $enabledSections = $client->defaultReportSections();

        $html = $renderer->render($client, $account, $month, $enabledSections);
        $filename = $client->name.' — '.$month->format('F Y').' report.pdf';

        return Pdf::loadHTML($html)->setPaper('a4')->download($filename);
    }

    /** Same rule as the staff controller's own resolveMonth(). */
    private function resolveMonth(Request $request): Carbon
    {
        $raw = (string) $request->query('month', '');

        if ($raw !== '') {
            try {
                return Carbon::createFromFormat('Y-m-d', $raw.'-01')->startOfDay();
            } catch (\Throwable) {
                // An unparsable month falls back rather than 500ing on a
                // typo in the address bar.
            }
        }

        return now()->subMonthNoOverflow()->startOfMonth();
    }

    private function instagramFor(Client $client): ?SocialAccount
    {
        return $client->socialAccounts()
            ->forPlatform(SocialAccount::PLATFORM_INSTAGRAM)
            ->connected()
            ->first();
    }
}
