<?php

namespace Tests\Feature;

use App\Models\Proposal;
use App\Models\User;
use App\Support\ProposalBlocks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProposalChartAndImageTest extends TestCase
{
    use RefreshDatabase;

    private function proposalWith(array $blocks): Proposal
    {
        $proposal = Proposal::create([
            'title' => 'Charts',
            'status' => Proposal::STATUS_DRAFT,
            'sections' => [['type' => 'section', 'data' => ['number' => '01', 'title' => 'Scope', 'blocks' => $blocks]]],
        ]);
        $proposal->issuePublicToken();

        return $proposal->fresh();
    }

    private function chart(string $kind, array $items = ['A' => 60, 'B' => 30, 'C' => 10]): array
    {
        return [
            'type' => 'chart', 'kind' => $kind, 'title' => 'Leads by source', 'caption' => 'Sample figures.',
            'items' => array_map(fn ($l, $v) => ['label' => $l, 'value' => $v], array_keys($items), $items),
        ];
    }

    public function test_a_chart_block_is_normalised(): void
    {
        $block = ProposalBlocks::normalizeBlock([
            'type' => 'chart', 'kind' => 'pie-that-does-not-exist', 'title' => 'T',
            'items' => [['label' => 'A', 'value' => '12.5'], ['label' => 'B', 'value' => -4], 'junk'],
        ]);

        $this->assertSame('bar', $block['kind'], 'an unknown kind falls back to a bar chart');
        $this->assertSame([['label' => 'A', 'value' => 12.5], ['label' => 'B', 'value' => 0.0]], $block['items']);
    }

    public function test_bar_lengths_scale_to_the_largest_value_and_survive_all_zeros(): void
    {
        $rows = ProposalBlocks::chartRows([['label' => 'A', 'value' => 20.0], ['label' => 'B', 'value' => 5.0]]);

        $this->assertSame(1.0, $rows[0]['share']);
        $this->assertSame(0.25, $rows[1]['share']);
        $this->assertSame(80.0, $rows[0]['percent']);

        $zero = ProposalBlocks::chartRows([['label' => 'A', 'value' => 0.0]]);
        $this->assertSame(0.0, $zero[0]['share']);
        $this->assertSame(0.0, $zero[0]['percent']);
    }

    public function test_an_image_may_only_point_at_proposal_pictures(): void
    {
        $path = fn ($p) => ProposalBlocks::normalizeBlock(['type' => 'image', 'path' => $p])['path'];

        $this->assertSame('images/proposals/thillai/lead-dashboard.svg', $path('images/proposals/thillai/lead-dashboard.svg'));
        $this->assertSame('', $path('uploads/clients/logo.png'), 'outside the proposal folders');
        $this->assertSame('', $path('images/proposals/../../.env'), 'no climbing out');
        $this->assertSame('', $path('images/proposals/notes.php'), 'pictures only');
    }

    public function test_every_chart_kind_draws_on_the_public_page(): void
    {
        $proposal = $this->proposalWith([$this->chart('bar'), $this->chart('donut'), $this->chart('funnel')]);

        $this->get(route('proposals.public', $proposal->public_token))
            ->assertOk()
            ->assertSee('cp-chart--bar', false)
            ->assertSee('cp-chart--donut', false)
            ->assertSee('cp-chart--funnel', false)
            ->assertSee('60%')            // donut share of the whole
            ->assertSee('50% of all leads') // funnel stage against the first
            ->assertSee('Sample figures.');
    }

    public function test_the_pdf_renders_charts_and_pictures(): void
    {
        $proposal = $this->proposalWith([
            $this->chart('donut'),
            ['type' => 'image', 'path' => 'images/proposals/thillai/lead-dashboard.svg', 'caption' => 'Preview', 'size' => 'full'],
        ]);

        $response = $this->get(route('proposals.public-pdf', $proposal->public_token));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    public function test_the_pdf_uses_the_png_beside_an_svg(): void
    {
        // DomPDF cannot draw SVG, so the rasterised copy is what it embeds.
        $this->assertSame(
            'images/proposals/thillai/lead-dashboard.png',
            ProposalBlocks::pdfImagePath('images/proposals/thillai/lead-dashboard.svg')
        );
        $this->assertSame(
            'images/proposals/print-bazzar.png',
            ProposalBlocks::pdfImagePath('images/proposals/print-bazzar.png')
        );
    }

    public function test_the_editor_keeps_chart_and_picture_blocks_on_save(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $proposal = $this->proposalWith([$this->chart('funnel')]);

        $sections = $proposal->sections;
        $sections[0]['data']['blocks'][] = ['type' => 'image', 'path' => 'images/proposals/thillai/analytics.svg', 'caption' => 'Insights', 'size' => 'medium'];

        $this->actingAs($admin)->put(route('proposals.update', $proposal), [
            'title' => $proposal->title,
            'status' => $proposal->status,
            'sections_json' => json_encode($sections),
        ]);

        $types = array_column($proposal->fresh()->sections[0]['data']['blocks'], 'type');
        $this->assertSame(['chart', 'image'], $types);
    }
}
