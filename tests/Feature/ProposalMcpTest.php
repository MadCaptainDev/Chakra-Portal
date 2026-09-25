<?php

namespace Tests\Feature;

use App\Models\McpToken;
use App\Models\Proposal;
use App\Models\User;
use App\Services\AdminAgent\ToolRegistry;
use App\Support\ProposalBlocks;
use App\Tools\Proposals\ProposalGuide;
use App\Tools\Proposals\ProposalToolSupport;
use Database\Seeders\ProposalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The proposal tools on the MCP server: Claude writing, revising and sending
 * a proposal end to end.
 */
class ProposalMcpTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    /** @return array<string, mixed> the raw tools/call result */
    private function callTool(User $user, string $tool, array $arguments = []): array
    {
        $token = McpToken::issue($user, 'Test')['plain'];

        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson(route('mcp'), [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => $tool, 'arguments' => $arguments],
            ])->assertOk()->json('result');
    }

    /** @return array<string, mixed> */
    private function data(User $user, string $tool, array $arguments = []): array
    {
        $result = $this->callTool($user, $tool, $arguments);
        $this->assertArrayNotHasKey('isError', $result, 'Tool error: '.$result['content'][0]['text']);

        return json_decode($result['content'][0]['text'], true);
    }

    private function errorText(User $user, string $tool, array $arguments = []): string
    {
        $result = $this->callTool($user, $tool, $arguments);
        $this->assertTrue($result['isError'] ?? false, 'Expected an error, got: '.$result['content'][0]['text']);

        return $result['content'][0]['text'];
    }

    private function sections(): array
    {
        return [
            ['key' => 'cover', 'type' => 'cover', 'data' => ['title' => 'Acme', 'client_name' => 'Acme', 'date_label' => 'October 2026']],
            ['key' => 'summary', 'type' => 'section', 'data' => ['number' => '01', 'title' => 'Summary', 'new_page' => true, 'blocks' => [
                ['type' => 'paragraph', 'text' => 'We will build the **store**.'],
            ]]],
            ['key' => 'price', 'type' => 'section', 'number' => '02', 'title' => 'Price', 'blocks' => [
                ['type' => 'total', 'label' => 'Total', 'value' => '₹1,00,000'],
            ]],
        ];
    }

    /* ------------------------------------------------------------ guide */

    public function test_every_guide_example_is_accepted_without_problems(): void
    {
        foreach (ProposalGuide::EXAMPLES as $type => $example) {
            $result = ProposalToolSupport::prepare([
                ['key' => 'x', 'type' => 'section', 'data' => ['number' => '01', 'title' => 'T', 'blocks' => [$example]]],
            ]);

            $this->assertSame([], $result['problems'], $type.' example has problems');
            $this->assertSame($type, $result['sections'][0]['data']['blocks'][0]['type']);
        }

        $this->assertSame(array_keys(ProposalBlocks::BLOCK_TYPES), array_keys(ProposalGuide::EXAMPLES), 'Every block type needs a guide example.');
    }

    public function test_the_guide_is_readable(): void
    {
        $guide = $this->data($this->admin(), 'proposal_guide');

        $this->assertCount(count(ProposalBlocks::BLOCK_TYPES), $guide['blocks']);
        $this->assertNotEmpty($guide['workflow']);
    }

    /* ------------------------------------------------------ who sees them */

    public function test_proposal_tools_follow_the_proposals_permission_and_stay_off_whatsapp(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $names = fn (User $u) => array_map(fn ($t) => $t->name(), app(\App\Mcp\Server::class)->toolsFor($u));

        $this->assertNotContains('create_proposal', $names($employee));

        $employee->syncPermissions(['proposals' => ['view']]);
        $employee->refresh();
        $this->assertContains('get_proposal', $names($employee));
        $this->assertNotContains('write_proposal_sections', $names($employee));

        $admin = $this->admin();
        $this->assertContains('delete_proposal', $names($admin));

        $whatsapp = collect(app(ToolRegistry::class)->definitions($admin))->pluck('name');
        $this->assertNotContains('create_proposal', $whatsapp);
        $this->assertNotContains('proposal_guide', $whatsapp);
    }

    /* ----------------------------------------------------------- writing */

    public function test_a_proposal_is_created_with_its_whole_document(): void
    {
        $admin = $this->admin();

        $created = $this->data($admin, 'create_proposal', [
            'title' => 'Acme — Store',
            'valid_until' => '2026-12-31',
            'sections' => $this->sections(),
        ]);

        $this->assertSame([], $created['problems']);
        $this->assertSame(['cover', 'summary', 'price'], array_column($created['outline'], 'key'));
        $this->assertSame([1, 2, 2], array_column($created['outline'], 'page'));

        $proposal = Proposal::findOrFail($created['id']);
        $this->assertSame(Proposal::STATUS_DRAFT, $proposal->status);
        $this->assertSame('₹1,00,000', $proposal->normalizedSections()[2]['data']['blocks'][0]['value']);
        $this->assertStringContainsString('/proposals/'.$proposal->id.'/edit', $created['edit_url']);
    }

    public function test_an_unknown_block_type_is_refused_and_bad_values_are_reported(): void
    {
        $admin = $this->admin();

        $error = $this->errorText($admin, 'create_proposal', [
            'title' => 'X',
            'sections' => [['key' => 'a', 'type' => 'section', 'blocks' => [['type' => 'heading', 'text' => 'Hi']]]],
        ]);
        $this->assertStringContainsString('subheading', $error);
        $this->assertSame(0, Proposal::count());

        $created = $this->data($admin, 'create_proposal', [
            'title' => 'X',
            'sections' => [['key' => 'a', 'type' => 'section', 'blocks' => [
                ['type' => 'callout', 'text' => 'Hi', 'tone' => 'purple', 'colour' => 'red'],
                ['type' => 'cards', 'columns' => 9, 'items' => [['label' => 'A', 'text' => 'B']]],
            ]]],
        ]);

        $problems = implode("\n", $created['problems']);
        $this->assertStringContainsString('"purple" is not an allowed value', $problems);
        $this->assertStringContainsString('"colour" is not a field', $problems);
        $this->assertStringContainsString('columns was adjusted to 4', $problems);
    }

    public function test_sections_are_rewritten_by_key_and_new_ones_placed(): void
    {
        $admin = $this->admin();
        $id = $this->data($admin, 'create_proposal', ['title' => 'Acme', 'sections' => $this->sections()])['id'];

        // The cover's uploaded logo must survive a cover rewrite that says
        // nothing about it.
        $proposal = Proposal::find($id);
        $sections = $proposal->normalizedSections();
        $sections[0]['data']['client_logo'] = 'images/proposals/print-bazzar.png';
        $proposal->forceFill(['sections' => $sections])->save();

        $result = $this->data($admin, 'write_proposal_sections', [
            'proposal' => (string) $id,
            'after' => 'summary',
            'sections' => [
                ['key' => 'cover', 'type' => 'cover', 'data' => ['title' => 'Acme Ltd', 'client_name' => 'Acme Ltd']],
                ['key' => 'summary', 'type' => 'section', 'data' => ['number' => '01', 'title' => 'Executive Summary', 'new_page' => true, 'blocks' => [
                    ['type' => 'paragraph', 'text' => 'Rewritten.'],
                ]]],
                ['key' => 'scope', 'type' => 'section', 'data' => ['number' => '02', 'title' => 'Scope', 'blocks' => [
                    ['type' => 'list', 'items' => ['One', 'Two']],
                ]]],
            ],
        ]);

        $this->assertSame([], $result['problems']);
        $this->assertSame(['cover', 'summary', 'scope', 'price'], array_column($result['outline'], 'key'));

        $stored = Proposal::find($id)->normalizedSections();
        $this->assertSame('Acme Ltd', $stored[0]['data']['title']);
        $this->assertSame('images/proposals/print-bazzar.png', $stored[0]['data']['client_logo']);
        $this->assertSame('Rewritten.', $stored[1]['data']['blocks'][0]['text']);
    }

    public function test_sections_are_reordered_and_removed(): void
    {
        $admin = $this->admin();
        $id = $this->data($admin, 'create_proposal', ['title' => 'Acme', 'sections' => $this->sections()])['id'];

        $this->assertStringContainsString('every remaining section key', $this->errorText($admin, 'arrange_proposal_sections', [
            'proposal' => (string) $id, 'order' => ['price'],
        ]));

        $result = $this->data($admin, 'arrange_proposal_sections', [
            'proposal' => (string) $id, 'order' => ['price', 'summary'],
        ]);
        $this->assertSame(['cover', 'price', 'summary'], array_column($result['outline'], 'key'));

        $result = $this->data($admin, 'arrange_proposal_sections', ['proposal' => (string) $id, 'remove' => ['price']]);
        $this->assertSame(['cover', 'summary'], array_column($result['outline'], 'key'));
    }

    public function test_a_proposal_is_read_back_in_full_or_by_section(): void
    {
        $admin = $this->admin();
        $this->seed(ProposalSeeder::class);

        $outline = $this->data($admin, 'get_proposal', ['proposal' => 'Print Bazzar']);
        $this->assertArrayNotHasKey('sections', $outline);
        $this->assertGreaterThan(10, count($outline['outline']));

        $one = $this->data($admin, 'get_proposal', ['proposal' => 'Print Bazzar', 'sections' => ['executive-summary']]);
        $this->assertCount(1, $one['sections']);

        // What comes out goes straight back in with nothing to report.
        $all = $this->data($admin, 'get_proposal', ['proposal' => 'Print Bazzar', 'sections' => ['*']]);
        $this->assertSame([], ProposalToolSupport::prepare($all['sections'])['problems']);
    }

    public function test_details_and_status_are_updated(): void
    {
        $admin = $this->admin();
        $id = $this->data($admin, 'create_proposal', ['title' => 'Acme'])['id'];

        $result = $this->data($admin, 'update_proposal', ['proposal' => (string) $id, 'status' => 'accepted', 'title' => 'Acme v2']);

        $this->assertSame('accepted', $result['status']);
        $this->assertSame('Acme v2', Proposal::find($id)->title);
    }

    public function test_duplicating_copies_the_document(): void
    {
        $admin = $this->admin();
        $this->seed(ProposalSeeder::class);

        $copy = $this->data($admin, 'create_proposal', ['title' => 'Next client', 'duplicate_of' => 'Print Bazzar']);

        $this->assertSame(
            Proposal::where('title', ProposalSeeder::TITLE)->first()->normalizedSections(),
            Proposal::find($copy['id'])->normalizedSections()
        );
    }

    /* ---------------------------------------------- comments and sharing */

    public function test_client_comments_are_listed_answered_and_resolved(): void
    {
        $admin = $this->admin();
        $id = $this->data($admin, 'create_proposal', ['title' => 'Acme', 'sections' => $this->sections()])['id'];
        $proposal = Proposal::find($id);
        $thread = $proposal->comments()->create([
            'section_key' => 'price', 'author_name' => 'Ravi', 'author_email' => 'r@example.com', 'body' => 'Can this come down?',
        ]);

        $list = $this->data($admin, 'list_proposal_comments', ['proposal' => (string) $id]);
        $this->assertSame('02 Price', $list['threads'][0]['section']);
        $this->assertSame('Ravi (client)', $list['threads'][0]['by']);

        $this->data($admin, 'reply_to_proposal_comment', [
            'proposal' => (string) $id, 'comment_id' => $thread->id, 'body' => 'We can phase it.', 'resolve' => true,
        ]);

        $this->assertTrue($thread->fresh()->isResolved());
        $this->assertSame('We can phase it.', $thread->replies()->first()->body);
        $this->assertSame([], $this->data($admin, 'list_proposal_comments', ['proposal' => (string) $id])['threads']);

        $this->data($admin, 'set_proposal_comment_status', ['proposal' => (string) $id, 'comment_id' => $thread->id, 'resolved' => false]);
        $this->assertFalse($thread->fresh()->isResolved());
    }

    public function test_the_client_link_is_made_replaced_and_closed(): void
    {
        $admin = $this->admin();
        $id = $this->data($admin, 'create_proposal', ['title' => 'Acme'])['id'];

        $first = $this->data($admin, 'share_proposal', ['proposal' => (string) $id]);
        $this->assertStringContainsString('/p/', $first['client_link']);
        $this->assertSame('sent', $first['status']);
        $this->assertSame($first['client_link'], $this->data($admin, 'share_proposal', ['proposal' => (string) $id])['client_link']);

        $renewed = $this->data($admin, 'share_proposal', ['proposal' => (string) $id, 'action' => 'new_link']);
        $this->assertNotSame($first['client_link'], $renewed['client_link']);

        $this->assertNull($this->data($admin, 'share_proposal', ['proposal' => (string) $id, 'action' => 'close'])['client_link']);
    }

    public function test_deleting_needs_the_exact_title(): void
    {
        $admin = $this->admin();
        $id = $this->data($admin, 'create_proposal', ['title' => 'Acme'])['id'];

        $this->errorText($admin, 'delete_proposal', ['proposal' => (string) $id, 'confirm_title' => 'acme']);
        $this->assertNotNull(Proposal::find($id));

        $this->data($admin, 'delete_proposal', ['proposal' => (string) $id, 'confirm_title' => 'Acme']);
        $this->assertNull(Proposal::find($id));
    }
}
