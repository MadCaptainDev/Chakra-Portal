<?php

namespace App\Services\AdReports;

use App\Models\AdReport;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Takes a paid-ads monthly report -- the JSON the report prompt produces --
 * checks it, and stores it.
 *
 * The expected shape has exactly these top-level keys:
 *
 *   report, ad_accounts, summary, campaigns, funds, budget_changes,
 *   timeline, insights, recommendations
 *
 * Two kinds of finding, kept apart on purpose:
 *
 * - problems(): the document cannot be shown at all (a key missing, a date
 *   that is not a date). Importing refuses.
 * - warnings(): the document is well-formed but its numbers disagree with each
 *   other. These are the checks the report prompt itself asks for -- campaign
 *   spend should add up to the total, daily charges to the amount billed, and
 *   so on. They do not stop an import, because a ₹0.03 rounding difference is
 *   not worth blocking a client's report over; but they are returned so
 *   whoever imports it sees them before sending the link on.
 */
class AdReportImporter
{
    public const REQUIRED_KEYS = [
        'report', 'ad_accounts', 'summary', 'campaigns', 'funds',
        'budget_changes', 'timeline', 'insights', 'recommendations',
    ];

    /** Beyond this the file is not a report, it is something else. */
    public const MAX_BYTES = 1_000_000;

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    public function decode(string $json): array
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException('That file is too large to be a report.');
        }

        $data = json_decode($json, true);

        if (! is_array($data)) {
            throw new InvalidArgumentException('That is not valid JSON: '.json_last_error_msg().'.');
        }

        return $data;
    }

    /**
     * Why this cannot be imported at all. Empty means it can.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public function problems(array $data): array
    {
        $problems = [];

        foreach (self::REQUIRED_KEYS as $key) {
            if (! array_key_exists($key, $data)) {
                $problems[] = "Missing the \"{$key}\" section.";
            }
        }

        if ($problems !== []) {
            return $problems;
        }

        foreach (['ad_accounts', 'campaigns', 'budget_changes', 'timeline', 'recommendations'] as $list) {
            if (! is_array($data[$list])) {
                $problems[] = "\"{$list}\" must be a list.";
            }
        }
        foreach (['report', 'summary', 'funds', 'insights'] as $object) {
            if (! is_array($data[$object])) {
                $problems[] = "\"{$object}\" must be an object.";
            }
        }

        if ($problems !== []) {
            return $problems;
        }

        $period = $data['report']['period'] ?? null;

        if (! is_array($period) || ! $this->isDate($period['start'] ?? null) || ! $this->isDate($period['end'] ?? null)) {
            $problems[] = 'The report period needs a start and an end date, written YYYY-MM-DD.';
        } elseif ($period['start'] > $period['end']) {
            $problems[] = 'The report period ends before it starts.';
        } elseif (Carbon::parse($period['start'])->diffInDays(Carbon::parse($period['end'])) > 92) {
            // The page draws one bar per day of the period; a "period" of
            // years is a typo, not a report, and would draw thousands.
            $problems[] = 'The report period is longer than a quarter — a report covers one month.';
        }

        if (blank($data['report']['client'] ?? null)) {
            $problems[] = 'The report does not say which client it is for.';
        }

        if (! is_numeric($data['summary']['amount_spent'] ?? null)) {
            $problems[] = 'The summary has no amount_spent.';
        }

        foreach ($data['campaigns'] as $i => $campaign) {
            if (! is_array($campaign) || blank($campaign['name'] ?? null) || ! is_numeric($campaign['amount_spent'] ?? null)) {
                $problems[] = 'Campaign '.($i + 1).' needs a name and an amount_spent.';
            }
        }

        foreach (['total_added', 'total_billed'] as $field) {
            if (! is_numeric($data['funds'][$field] ?? null)) {
                $problems[] = "funds.{$field} must be a number.";
            }
        }
        if (! is_array($data['funds']['daily_charges'] ?? null) || ! is_array($data['funds']['top_ups'] ?? null)) {
            $problems[] = 'funds needs top_ups and daily_charges lists.';
        }

        return $problems;
    }

    /**
     * Where the numbers disagree with each other. Empty means they all agree.
     *
     * Only meaningful once problems() is empty.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public function warnings(array $data): array
    {
        $warnings = [];
        $summary = $data['summary'];
        $funds = $data['funds'];
        $campaigns = $data['campaigns'];

        // A campaign's own figures are each rounded to the paisa, so the total
        // of N of them can be out by up to N paise without anything being wrong.
        $tolerance = max(0.05, 0.01 * count($campaigns));

        $spend = $this->num($summary['amount_spent']);
        $campaignSpend = array_sum(array_map(fn ($c) => $this->num($c['amount_spent']), $campaigns));

        if (abs($campaignSpend - $spend) > $tolerance) {
            $warnings[] = 'Campaign spend adds up to '.$this->rupees($campaignSpend)
                .' but the summary says '.$this->rupees($spend).'.';
        }

        if (is_numeric($summary['results'] ?? null)) {
            $campaignResults = array_sum(array_map(fn ($c) => $this->num($c['results'] ?? 0), $campaigns));

            if (round($campaignResults) !== round($this->num($summary['results']))) {
                $warnings[] = "Campaign results add up to {$campaignResults} but the summary says {$summary['results']}.";
            }
        }

        $billed = $this->num($funds['total_billed']);
        $charges = array_sum(array_map(fn ($d) => $this->num($d['amount'] ?? 0), $funds['daily_charges']));

        if (abs($charges - $billed) > $tolerance) {
            $warnings[] = 'Daily charges add up to '.$this->rupees($charges)
                .' but the report says '.$this->rupees($billed).' was billed.';
        }

        if (abs($billed - $spend) > $tolerance) {
            $warnings[] = 'The amount billed ('.$this->rupees($billed)
                .') is not the amount spent ('.$this->rupees($spend).').';
        }

        $added = $this->num($funds['total_added']);
        $topUps = array_sum(array_map(fn ($t) => $this->num($t['amount'] ?? 0), $funds['top_ups']));

        if (abs($topUps - $added) > $tolerance) {
            $warnings[] = 'Top-ups add up to '.$this->rupees($topUps)
                .' but the report says '.$this->rupees($added).' was added.';
        }

        if (is_numeric($funds['difference'] ?? null)
            && abs(($added - $billed) - $this->num($funds['difference'])) > $tolerance) {
            $warnings[] = 'The funds difference should be '.$this->rupees($added - $billed)
                .', but the report says '.$this->rupees($this->num($funds['difference'])).'.';
        }

        $warnings = [...$warnings, ...$this->bestPerformerWarnings($campaigns)];
        $warnings = [...$warnings, ...$this->gapWarnings($data)];

        return $warnings;
    }

    /**
     * Store it, replacing that client's report for the same month.
     *
     * A re-import keeps the existing link as it was -- a client who already
     * has the address gets the corrected figures at it -- but never switches
     * a link back on that someone switched off.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException when problems() is not empty
     */
    public function import(array $data, ?int $clientId, ?int $userId = null): AdReport
    {
        $problems = $this->problems($data);

        if ($problems !== []) {
            throw new InvalidArgumentException(implode(' ', $problems));
        }

        $period = $data['report']['period'];
        $platform = (string) ($data['report']['platform'] ?? 'Meta Ads');

        // whereDate, not an equality on the string: a date cast can store
        // "2026-09-01 00:00:00" on some drivers, and equality against
        // "2026-09-01" would then miss the row it is meant to replace.
        $report = AdReport::query()
            ->when($clientId === null, fn ($q) => $q->whereNull('client_id'), fn ($q) => $q->where('client_id', $clientId))
            ->where('platform', $platform)
            ->whereDate('period_start', $period['start'])
            ->first();

        $isNew = $report === null;
        $report ??= new AdReport;

        $report->fill([
            'client_id' => $clientId,
            'platform' => $platform,
            'period_start' => $period['start'],
            'period_end' => $period['end'],
            'title' => $platform.' report — '.$data['report']['client'].' — '
                .($period['label'] ?? Carbon::parse($period['start'])->format('F Y')),
            'data' => $data,
        ]);

        if ($isNew) {
            $report->created_by_id = $userId;
        }

        $report->save();

        if ($isNew) {
            $report->issuePublicToken();
        }

        return $report;
    }

    /**
     * The report marks the cheapest campaign (with at least 10 results, so a
     * lucky one-result campaign cannot win) as the best performer. Check that
     * it is actually the cheapest.
     *
     * @param  list<array<string, mixed>>  $campaigns
     * @return list<string>
     */
    private function bestPerformerWarnings(array $campaigns): array
    {
        $flagged = array_values(array_filter($campaigns, fn ($c) => ($c['best_performer'] ?? false) === true));

        if (count($flagged) > 1) {
            return ['More than one campaign is marked as the best performer.'];
        }

        $eligible = array_filter(
            $campaigns,
            fn ($c) => $this->num($c['results'] ?? 0) >= 10 && is_numeric($c['cost_per_result'] ?? null)
        );

        if ($eligible === []) {
            return $flagged === [] ? [] : ['A best performer is marked, but no campaign has 10 results.'];
        }

        $cheapest = min(array_map(fn ($c) => $this->num($c['cost_per_result']), $eligible));

        if ($flagged === []) {
            return ['No campaign is marked as the best performer, but one qualifies.'];
        }

        if (abs($this->num($flagged[0]['cost_per_result'] ?? PHP_INT_MAX) - $cheapest) > 0.005) {
            return ["The campaign marked as best performer is not the cheapest per result (that is {$this->rupees($cheapest)})."];
        }

        return [];
    }

    /**
     * Days listed as having no charge must lie inside the period and must not
     * also appear as a day that was charged.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function gapWarnings(array $data): array
    {
        $warnings = [];
        $period = $data['report']['period'];
        $charged = array_column($data['funds']['daily_charges'], 'date');

        foreach ((array) ($data['funds']['no_charge_dates'] ?? []) as $date) {
            if (in_array($date, $charged, true)) {
                $warnings[] = "{$date} is listed as having no charge, but it also has a daily charge.";
            }
            if ($this->isDate($date) && ($date < $period['start'] || $date > $period['end'])) {
                $warnings[] = "{$date} is listed as a no-charge day but is outside the report period.";
            }
        }

        if (substr($period['start'], 0, 7) !== substr($period['end'], 0, 7)) {
            $warnings[] = 'The period spans more than one calendar month.';
        }

        return $warnings;
    }

    private function isDate(mixed $value): bool
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        // 2026-02-30 matches the pattern, and depending on Carbon's strictness
        // either throws or quietly rolls over to 2 March. Both mean "not a
        // date", so the round trip is compared and the throw is caught.
        try {
            return Carbon::createFromFormat('Y-m-d', $value)->format('Y-m-d') === $value;
        } catch (\Throwable) {
            return false;
        }
    }

    private function num(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function rupees(float $amount): string
    {
        return '₹'.number_format($amount, 2);
    }
}
