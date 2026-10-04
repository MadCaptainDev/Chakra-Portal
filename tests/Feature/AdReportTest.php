<?php

namespace Tests\Feature;

use App\Models\AdReport;
use App\Models\Client;
use App\Models\User;
use App\Services\AdReports\AdReportImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class AdReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The page's stylesheet is a Vite entry; the test should not depend
        // on whether assets happen to have been built.
        $this->withoutVite();
    }

    private function importer(): AdReportImporter
    {
        return app(AdReportImporter::class);
    }

    /**
     * A small report whose numbers all agree with each other: 200 + 100
     * spent, 10 + 20 results, charges of 100 + 200 against 300 billed, one
     * top-up of 354 (300 plus GST), and the cheaper campaign marked best.
     *
     * @return array<string, mixed>
     */
    private function report(array $overrides = []): array
    {
        $data = [
            'report' => [
                'client' => 'Thillai Pet Clinic',
                'platform' => 'Meta Ads',
                'period' => ['label' => 'September 2026', 'start' => '2026-09-01', 'end' => '2026-09-30'],
                'currency' => 'INR',
                'generated_at' => '2026-10-04',
                'source' => 'Meta Ads Manager',
                'attribution' => 'Meta default',
            ],
            'ad_accounts' => [
                ['ad_account_id' => '408316460599154', 'status' => 'ACTIVE', 'spend' => 300.00, 'included_in_report' => true],
            ],
            'summary' => [
                'amount_spent' => 300.00, 'results_type' => 'messaging_conversations_started', 'results' => 30,
                'cost_per_result' => 10.00, 'reach' => 5000, 'impressions' => 9000, 'frequency' => 1.8,
                'clicks' => 120, 'link_clicks' => 60, 'ctr_percent' => 1.33, 'cpc' => 2.50, 'cpm' => 33.33,
                'campaigns_with_spend' => 2,
            ],
            'campaigns' => [
                [
                    'campaign_id' => '111', 'name' => 'Grooming', 'objective' => 'OUTCOME_TRAFFIC', 'status' => 'ACTIVE',
                    'launched' => '2026-09-01', 'amount_spent' => 200.00, 'results' => 10, 'cost_per_result' => 20.00,
                    'reach' => 3000, 'impressions' => 6000, 'frequency' => 2.0, 'clicks' => 80, 'link_clicks' => 40,
                    'ctr_percent' => 1.33, 'cpc' => 2.50, 'cpm' => 33.33,
                ],
                [
                    'campaign_id' => '222', 'name' => 'Vaccination', 'objective' => 'OUTCOME_TRAFFIC', 'status' => 'PAUSED',
                    'launched' => '2026-09-10', 'amount_spent' => 100.00, 'results' => 20, 'cost_per_result' => 5.00,
                    'reach' => 2500, 'impressions' => 3000, 'frequency' => 1.2, 'clicks' => 40, 'link_clicks' => 20,
                    'ctr_percent' => 1.33, 'cpc' => 2.50, 'cpm' => 33.33, 'best_performer' => true,
                ],
            ],
            'funds' => [
                'total_added' => 354.00, 'total_billed' => 300.00, 'difference' => 54.00, 'difference_note' => 'About 18% GST',
                'top_ups' => [['date' => '2026-09-01', 'time' => '10:00', 'method' => 'UPI', 'amount' => 354.00]],
                'daily_charges' => [['date' => '2026-09-02', 'amount' => 100.00], ['date' => '2026-09-03', 'amount' => 200.00]],
                'no_charge_dates' => ['2026-09-01'],
                'no_charge_note' => 'Nothing ran before the first top-up',
            ],
            'budget_changes' => [
                ['date' => '2026-09-05', 'time' => '18:35', 'by' => 'KDrop', 'ad_set' => 'Grooming', 'daily_budget_from' => 507, 'daily_budget_to' => 207],
            ],
            'timeline' => [['date' => '2026-09-01', 'event' => 'Grooming launched', 'by' => 'KDrop', 'flag' => true]],
            'insights' => ['wins' => ['Vaccination at ₹5 a chat'], 'issues' => ['Grooming costs four times as much']],
            'recommendations' => ['Move budget to Vaccination'],
        ];

        return array_replace_recursive($data, $overrides);
    }

    /* ---------------------------------------------------------- checks */

    public function test_a_consistent_report_has_no_problems_and_no_warnings(): void
    {
        $this->assertSame([], $this->importer()->problems($this->report()));
        $this->assertSame([], $this->importer()->warnings($this->report()));
    }

    public function test_a_missing_section_cannot_be_imported(): void
    {
        $data = $this->report();
        unset($data['funds']);

        $this->assertContains('Missing the "funds" section.', $this->importer()->problems($data));

        $this->expectException(InvalidArgumentException::class);
        $this->importer()->import($data, null);
    }

    public function test_a_period_that_is_not_one_real_month_is_refused(): void
    {
        $problems = fn (array $period) => $this->importer()->problems($this->report(['report' => ['period' => $period]]));

        $this->assertNotEmpty($problems(['start' => '2026-09-30', 'end' => '2026-09-01']), 'ends before it starts');
        $this->assertNotEmpty($problems(['start' => '2026-02-01', 'end' => '2026-02-30']), 'no 30 February');
        $this->assertNotEmpty($problems(['start' => '2020-01-01', 'end' => '2026-09-30']), 'years, not a month');
    }

    public function test_campaign_spend_that_does_not_add_up_is_flagged(): void
    {
        $warnings = $this->importer()->warnings($this->report(['campaigns' => [0 => ['amount_spent' => 250.00]]]));

        $this->assertNotEmpty(array_filter($warnings, fn ($w) => str_contains($w, 'Campaign spend adds up to ₹350.00')));
    }

    public function test_paisa_rounding_is_not_flagged(): void
    {
        // Two campaigns each rounded to the paisa can be a paisa or two out.
        $this->assertSame([], $this->importer()->warnings($this->report(['summary' => ['amount_spent' => 300.01]])));
    }

    public function test_daily_charges_that_do_not_match_the_bill_are_flagged(): void
    {
        $warnings = $this->importer()->warnings($this->report(['funds' => ['daily_charges' => [1 => ['amount' => 150.00]]]]));

        $this->assertNotEmpty(array_filter($warnings, fn ($w) => str_contains($w, 'Daily charges add up to ₹250.00')));
    }

    public function test_the_best_performer_must_really_be_the_cheapest(): void
    {
        $wrong = $this->report(['campaigns' => [0 => ['best_performer' => true], 1 => ['best_performer' => false]]]);
        $this->assertNotEmpty(array_filter(
            $this->importer()->warnings($wrong),
            fn ($w) => str_contains($w, 'not the cheapest')
        ));

        $none = $this->report(['campaigns' => [1 => ['best_performer' => false]]]);
        $this->assertContains('No campaign is marked as the best performer, but one qualifies.', $this->importer()->warnings($none));
    }

    public function test_a_lucky_campaign_with_few_results_cannot_be_best(): void
    {
        // Cheaper than anything, but on 3 results: not eligible, so the
        // 20-result campaign stays the rightful best performer.
        $data = $this->report();
        $data['campaigns'][] = [
            'campaign_id' => '333', 'name' => 'Lucky', 'amount_spent' => 0.00, 'results' => 3, 'cost_per_result' => 0.50,
        ];

        $this->assertSame([], array_values(array_filter(
            $this->importer()->warnings($data),
            fn ($w) => str_contains($w, 'best performer')
        )));
    }

    public function test_a_no_charge_day_that_was_charged_is_flagged(): void
    {
        $warnings = $this->importer()->warnings($this->report(['funds' => ['no_charge_dates' => ['2026-09-02']]]));

        $this->assertContains('2026-09-02 is listed as having no charge, but it also has a daily charge.', $warnings);
    }

    /* ---------------------------------------------------------- import */

    public function test_importing_creates_the_report_with_a_link(): void
    {
        $client = Client::create(['name' => 'Thillai Pets Clinic']);

        $report = $this->importer()->import($this->report(), $client->id);

        $this->assertSame($client->id, $report->client_id);
        $this->assertSame('Meta Ads report — Thillai Pet Clinic — September 2026', $report->title);
        $this->assertSame('2026-09-01', $report->period_start->toDateString());
        $this->assertSame(48, strlen($report->public_token));
        $this->assertStringContainsString('/results/'.$report->public_token, $report->publicUrl());
    }

    public function test_importing_the_same_month_again_replaces_it_and_keeps_the_link(): void
    {
        $client = Client::create(['name' => 'Thillai Pets Clinic']);
        $first = $this->importer()->import($this->report(), $client->id);

        $corrected = $this->importer()->import($this->report(['recommendations' => [0 => 'Corrected advice']]), $client->id);

        $this->assertSame(1, AdReport::count());
        $this->assertSame($first->id, $corrected->id);
        $this->assertSame($first->public_token, $corrected->fresh()->public_token, 'the client keeps the link they already have');
        $this->assertSame('Corrected advice', $corrected->fresh()->data['recommendations'][0]);
    }

    public function test_a_switched_off_link_stays_off_after_a_reimport(): void
    {
        $client = Client::create(['name' => 'Thillai Pets Clinic']);
        $report = $this->importer()->import($this->report(), $client->id);
        $report->revokePublicToken();

        $this->importer()->import($this->report(), $client->id);

        $this->assertNull($report->fresh()->public_token);
    }

    public function test_different_months_and_clients_are_separate_reports(): void
    {
        $a = Client::create(['name' => 'A']);
        $b = Client::create(['name' => 'B']);

        $this->importer()->import($this->report(), $a->id);
        $this->importer()->import($this->report(), $b->id);
        $this->importer()->import($this->report(['report' => ['period' => ['label' => 'October 2026', 'start' => '2026-10-01', 'end' => '2026-10-31']]]), $a->id);

        $this->assertSame(3, AdReport::count());
    }

    /* ------------------------------------------------------------ page */

    public function test_the_public_page_shows_the_report(): void
    {
        $client = Client::create(['name' => 'Thillai Pets Clinic']);
        $report = $this->importer()->import($this->report(), $client->id);

        $this->get(route('ad-reports.public', $report->public_token))
            ->assertOk()
            ->assertSee('Thillai Pets Clinic')          // the portal's name for them, not the report's spelling
            ->assertSee('September 2026')
            ->assertSee('₹300.00')
            ->assertSee('Vaccination')
            ->assertSee('Best performer')
            ->assertSee('₹507')                          // budget, whole rupees
            ->assertSee('Move budget to Vaccination')
            ->assertSee('noindex', false);
    }

    public function test_text_from_the_report_is_escaped(): void
    {
        $report = $this->importer()->import($this->report([
            'campaigns' => [0 => ['name' => '<script>alert(1)</script>']],
        ]), null);

        $this->get(route('ad-reports.public', $report->public_token))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_a_missing_number_shows_as_a_dash_not_zero(): void
    {
        $report = $this->importer()->import($this->report(['summary' => ['reach' => null]]), null);

        $html = $this->get(route('ad-reports.public', $report->public_token))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/Reach<\/div>\s*<div class="ar-kpi__value">—<\/div>/', $html);
    }

    public function test_an_unknown_or_switched_off_link_is_not_found(): void
    {
        $report = $this->importer()->import($this->report(), null);
        $token = $report->public_token;

        $this->get(route('ad-reports.public', 'not-a-real-token'))->assertNotFound();

        $report->revokePublicToken();
        $this->get(route('ad-reports.public', $token))->assertNotFound();
    }

    public function test_staff_checking_the_link_do_not_count_as_the_client_viewing_it(): void
    {
        $report = $this->importer()->import($this->report(), null);

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->get(route('ad-reports.public', $report->public_token))->assertOk();
        $this->assertNull($report->fresh()->first_viewed_at);

        auth()->logout();

        $this->get(route('ad-reports.public', $report->public_token))->assertOk();
        $this->assertNotNull($report->fresh()->first_viewed_at);
    }

    /* --------------------------------------------------------- command */

    private function file(array $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'adreport');
        file_put_contents($path, json_encode($data));

        return $path;
    }

    public function test_the_command_will_not_guess_the_client_from_the_name(): void
    {
        Client::create(['name' => 'Thillai Pets Clinic']);

        $this->artisan('ad-reports:import', ['file' => $this->file($this->report())])
            ->expectsOutputToContain('will not guess')
            ->assertFailed();

        $this->assertSame(0, AdReport::count());
    }

    public function test_the_command_imports_and_prints_the_link(): void
    {
        $client = Client::create(['name' => 'Thillai Pets Clinic']);

        $this->artisan('ad-reports:import', ['file' => $this->file($this->report()), '--client' => $client->id])
            ->expectsOutputToContain('Numbers check out')
            ->expectsOutputToContain('/results/')
            ->assertSuccessful();

        $this->assertSame($client->id, AdReport::firstOrFail()->client_id);
    }

    public function test_the_command_refuses_a_broken_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'adreport');
        file_put_contents($path, '{not json');

        $this->artisan('ad-reports:import', ['file' => $path, '--no-client' => true])->assertFailed();
        $this->assertSame(0, AdReport::count());
    }
}
