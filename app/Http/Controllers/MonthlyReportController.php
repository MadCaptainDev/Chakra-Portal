<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\MonthlyReportNote;
use App\Models\SocialAccount;
use App\Services\Instagram\InstagramSyncRunner;
use App\Services\MonthlyReportData;
use App\Services\MonthlyReportDocumentRenderer;
use App\Services\WhatsappSender;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use RuntimeException;

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
        $enabledSections = $this->resolveSections($request, $client);

        if (! $account) {
            return view('instagram.report', [
                'client' => $client,
                'account' => null,
                'month' => $month,
                'since' => $since,
                'until' => $until,
                'enabledSections' => $enabledSections,
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
            'enabledSections' => $enabledSections,
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
        $enabledSections = $this->resolveSections($request, $client);

        $html = $renderer->render($client, $account, $month, $enabledSections);
        $filename = $client->name.' — '.$month->format('F Y').' report.pdf';

        return Pdf::loadHTML($html)->setPaper('a4')->download($filename);
    }

    /**
     * Persists the report screen's current section checklist as this
     * client's new default (Client::report_sections_disabled) -- the
     * "save as default" half of "per-client default, overridable per
     * send". The checklist itself lives on the report screen and this
     * only ever receives its own POST, so there is no month-specific data
     * to touch here, only the client's standing preference.
     */
    public function updateSections(Request $request, Client $client): RedirectResponse
    {
        $selected = $this->validSectionKeys((array) $request->input('sections', []));
        $disabled = array_values(array_diff(array_keys(Client::REPORT_SECTIONS), $selected));

        $client->update(['report_sections_disabled' => $disabled]);

        return redirect()->route('instagram.report', ['client' => $client, 'month' => $request->input('month')])
            ->with('status', 'Default sections saved for '.$client->name.'.');
    }

    /**
     * Renders the currently-selected sections into a PDF and sends it as a
     * WhatsApp document -- to whatever number is typed in, not necessarily
     * the client's own on file, same reasoning as InvoiceController::sendWhatsapp():
     * there is no one "the" recipient to lock this to. Free-form (see
     * WhatsappSender::sendDocument()'s own doc block), so this only ever
     * reaches a number within 24 hours of it last messaging the studio.
     */
    public function sendWhatsapp(Request $request, Client $client, MonthlyReportDocumentRenderer $renderer): RedirectResponse
    {
        $account = $this->instagramFor($client);

        if (! $account) {
            return redirect()->route('instagram.report', ['client' => $client])
                ->with('error', 'Connect Instagram for '.$client->name.' before sending a report.');
        }

        $validated = $request->validate([
            'phone' => ['required', 'string', 'min:10', 'max:20', 'regex:/^[0-9+\-\s()]+$/'],
            'month' => ['required', 'date_format:Y-m'],
        ], [
            'phone.regex' => 'That doesn\'t look like a phone number.',
        ]);

        $month = $this->parseMonth($validated['month']);
        $enabledSections = $this->validSectionKeys((array) $request->input('sections', []));

        $pdfContents = Pdf::loadHTML($renderer->render($client, $account, $month, $enabledSections))
            ->setPaper('a4')
            ->output();
        $filename = $client->name.' — '.$month->format('F Y').' report.pdf';

        try {
            WhatsappSender::make()->sendDocument(
                $validated['phone'],
                $pdfContents,
                $filename,
                $client->name.'\'s '.$month->format('F Y').' Instagram report.',
            );
        } catch (RuntimeException $e) {
            // Meta's own reason (outside the 24h window, number not
            // reachable, ...) is the useful part -- surfaced as-is rather
            // than a generic "failed to send".
            return redirect()->route('instagram.report', ['client' => $client, 'month' => $validated['month']])
                ->with('error', $e->getMessage());
        }

        MonthlyReportNote::forClientAndMonth($client, $month)
            ->forceFill(['whatsapp_sent_at' => now()])
            ->save();

        return redirect()->route('instagram.report', ['client' => $client, 'month' => $validated['month']])
            ->with('status', "Sent to {$validated['phone']} on WhatsApp.");
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

    /**
     * Which report sections this request is actually asking for.
     *
     * `sections_form` is a hidden marker the checklist form always
     * submits alongside `sections[]` -- without it, there would be no way
     * to tell "the form was submitted with every box unticked" (a real,
     * intentional choice) apart from "no sections param was ever sent at
     * all" (a fresh visit, no opinion yet), because an unchecked HTML
     * checkbox is simply absent from what the browser submits. Only the
     * second case falls back to the client's saved default.
     *
     * @return list<string>
     */
    private function resolveSections(Request $request, Client $client): array
    {
        if (! $request->has('sections_form')) {
            return $client->defaultReportSections();
        }

        return $this->validSectionKeys((array) $request->input('sections', []));
    }

    /**
     * @param  array<int, mixed>  $keys
     * @return list<string>
     */
    private function validSectionKeys(array $keys): array
    {
        return array_values(array_intersect($keys, array_keys(Client::REPORT_SECTIONS)));
    }
}
