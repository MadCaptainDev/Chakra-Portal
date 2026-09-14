<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Services\Ai\ChatModel;
use App\Services\MonthlyReportNoteWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\FakeChatModel;
use Tests\TestCase;

/**
 * The first draft of the note a client reads at the top of their monthly
 * report.
 *
 * The rule worth testing is the one about numbers. This paragraph goes to a
 * paying customer over the studio's name, and the worst thing it could do is
 * quote a reach figure that is not theirs — confidently, in a sentence that
 * reads perfectly well.
 */
class MonthlyReportNoteDraftTest extends TestCase
{
    use RefreshDatabase;

    private FakeChatModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->model = new FakeChatModel;
        $this->app->instance(ChatModel::class, $this->model);
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        return [
            'overview' => ['reach' => 48250, 'views' => 91300, 'engagement' => 3120, 'followers' => 4180],
            'shoots' => collect(),
            'content' => [],
        ];
    }

    private function draft(): string
    {
        return app(MonthlyReportNoteWriter::class)
            ->draft(Client::factory()->create(['name' => 'SVA Silks']), now()->startOfMonth(), $this->report());
    }

    public function test_a_draft_using_the_months_own_figures_comes_back(): void
    {
        $this->model->willSay('Reach came to 48,250 across the month, with 3,120 interactions. The account now sits at 4,180 followers.');

        $this->assertStringContainsString('48,250', $this->draft());
    }

    public function test_a_figure_that_is_not_in_the_report_is_refused(): void
    {
        // Reads perfectly well. The reach is not theirs.
        $this->model->willSay('Reach came to 52,900 across the month, a strong result.');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not in this month\'s report');

        $this->draft();
    }

    public function test_the_separators_the_studio_writes_are_not_read_as_a_different_number(): void
    {
        // 48250 in the facts, 48,250 in the prose. One number.
        $this->model->willSay('Reach was 48,250 this month.');

        $this->assertStringContainsString('48,250', $this->draft());
    }

    public function test_small_counts_and_years_are_left_alone(): void
    {
        // "three shoots" and "2026" are prose, not performance claims.
        // Flagging them would discard every usable draft.
        $this->model->willSay('A quiet month with 2 shoots; reach held at 48,250.');

        $this->assertStringContainsString('2 shoots', $this->draft());
    }

    public function test_an_empty_reply_is_an_error_rather_than_an_empty_note(): void
    {
        $this->model->willSay('');

        $this->expectException(RuntimeException::class);

        $this->draft();
    }

    public function test_the_model_is_told_the_facts_and_given_no_tools(): void
    {
        $this->model->willSay('Reach was 48,250.');
        $this->draft();

        $call = $this->model->calls[0];

        $this->assertStringContainsString('SVA Silks', $call['messages'][0]['said']);
        $this->assertStringContainsString('48,250', $call['messages'][0]['said']);
        // Nothing to look up: it is writing from the facts it was handed, and
        // a tool would let it wander into another client's month.
        $this->assertSame([], $call['tools']);
        $this->assertStringContainsString('Use only figures given to you', $call['system']);
    }
}
