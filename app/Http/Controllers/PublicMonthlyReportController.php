<?php

namespace App\Http\Controllers;

use App\Models\MonthlyReportNote;
use App\Models\SocialAccount;
use App\Services\MonthlyReportDocumentRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * A client's monthly report PDF, opened from the link in the WhatsApp
 * template (r/{token}) -- the way a report reaches someone who has not
 * messaged the studio in the last 24 hours, when an attached file cannot.
 *
 * Same rules as PublicInvoiceController: the token is the credential, an
 * unknown one is a 404. The report is rendered fresh, with the sections
 * that were ticked when it was sent.
 */
class PublicMonthlyReportController extends Controller
{
    public function pdf(string $token, MonthlyReportDocumentRenderer $renderer): Response
    {
        $note = MonthlyReportNote::with('client')->where('public_token', $token)->first();
        abort_if($note === null || $note->client === null, 404);

        $account = $note->client->socialAccounts()
            ->forPlatform(SocialAccount::PLATFORM_INSTAGRAM)
            ->connected()
            ->first();
        abort_if($account === null, 404);

        $month = $note->month->copy()->startOfMonth();
        $html = $renderer->render($note->client, $account, $month, $note->shared_sections);

        return Pdf::loadHTML($html)->setPaper('a4')
            ->stream($note->client->name.' — '.$month->format('F Y').' report.pdf');
    }
}
