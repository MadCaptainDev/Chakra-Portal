<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Enquiry;
use App\Models\McpToken;
use App\Models\PortfolioItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The admin's extra MCP tools: the portfolio (list, write a case study),
 * enquiries, announcements, shoots to book and the WhatsApp inbox.
 */
class McpAdminToolsTest extends TestCase
{
    use RefreshDatabase;

    private function rpc(User $user, string $name, array $arguments = []): array
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.McpToken::issue($user, 'Test')['plain']])
            ->postJson(route('mcp'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]])
            ->assertOk()->json('result');
    }

    private function data(User $user, string $name, array $arguments = []): array
    {
        $result = $this->rpc($user, $name, $arguments);
        $this->assertArrayNotHasKey('isError', $result, 'Tool error: '.($result['content'][0]['text'] ?? ''));

        return json_decode($result['content'][0]['text'], true);
    }

    public function test_an_admin_lists_the_portfolio_and_writes_a_case_study(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $item = PortfolioItem::create(['title' => 'Janet - 5 scans', 'client_name' => 'Janet', 'is_visible' => true]);

        $this->assertSame($item->id, $this->data($admin, 'list_portfolio', ['missing_case_study' => true])['items'][0]['id']);

        $this->data($admin, 'update_portfolio_item', ['id' => $item->id, 'summary' => 'A pregnancy explainer that reached 6.5M.', 'creative_hook' => 'Five scans, one question.', 'featured' => true]);
        $item->refresh();
        $this->assertSame('Five scans, one question.', $item->creative_hook);
        $this->assertTrue($item->is_featured);
        $this->assertSame([], $this->data($admin, 'list_portfolio', ['missing_case_study' => true])['items']);
    }

    public function test_enquiries_announcements_and_shoots_to_book(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        Enquiry::create(['name' => 'Priya', 'email' => 'priya@example.com', 'phone' => '9876543210', 'project' => 'Wedding film', 'message' => 'Need a quote']);

        $this->assertSame('Priya', $this->data($admin, 'list_enquiries')['enquiries'][0]['name']);

        $this->data($admin, 'post_announcement', ['title' => 'Office closed Monday', 'body' => 'Diwali holiday.']);
        $this->assertSame('Office closed Monday', Announcement::first()->title);
        Notification::assertSentTimes(\App\Notifications\AnnouncementPosted::class, 1);

        $this->assertArrayHasKey('book_now', $this->data($admin, 'book_shoots'));
        $this->assertSame(0, $this->data($admin, 'whatsapp_inbox')['count']);

        // An employee without those modules gets none of them.
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $names = collect($this->withHeaders(['Authorization' => 'Bearer '.McpToken::issue($employee, 'Test')['plain']])
            ->postJson(route('mcp'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->json('result.tools'))->pluck('name');
        foreach (['post_announcement', 'list_enquiries', 'book_shoots', 'whatsapp_inbox', 'update_portfolio_item'] as $tool) {
            $this->assertNotContains($tool, $names);
        }
        $this->assertSame(1, Announcement::count());
    }
}
