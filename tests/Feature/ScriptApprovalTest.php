<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Script;
use App\Models\User;
use App\Notifications\ScriptApproved;
use App\Notifications\ScriptChangesRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Sending a script to a client for approval, and the client's one-at-a-time
 * queue for deciding on it -- see Script::markSentToClient()/
 * approveByClient()/requestChangesByClient() and
 * Client\ScriptApprovalController.
 */
class ScriptApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function client(array $overrides = []): Client
    {
        return Client::create(array_merge([
            'name' => 'SVA Silks and Readymades',
            'notion_venture' => 'SVA Silks',
            // Off by default -- most tests want it on.
            'portal_sections_disabled' => [],
        ], $overrides));
    }

    private function loginFor(Client $client): User
    {
        return User::factory()->create(['role' => User::ROLE_CLIENT, 'client_id' => $client->id]);
    }

    private function staff(array $abilities = ['view', 'edit', 'approve']): User
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $user->syncPermissions(['scripts' => $abilities]);

        return $user->refresh();
    }

    private function script(Client $client, array $overrides = []): Script
    {
        // sent_to_client_at is stamped only by markSentToClient() and is
        // deliberately not fillable, so a test that wants one pre-set has to
        // go around mass assignment the same way that method does.
        $sentAt = $overrides['sent_to_client_at'] ?? null;
        unset($overrides['sent_to_client_at']);

        $script = Script::create(array_merge([
            'title' => 'Diwali reel',
            'status' => Script::STATUS_READY,
            'priority' => Script::PRIORITY_NORMAL,
            'client_id' => $client->id,
        ], $overrides));

        if ($sentAt) {
            $script->forceFill(['sent_to_client_at' => $sentAt])->save();
        }

        return $script;
    }

    // -- Sending (staff side) -------------------------------------------------

    public function test_staff_with_approve_can_send_a_ready_script_to_the_client(): void
    {
        $client = $this->client();
        $script = $this->script($client);

        $this->actingAs($this->staff())
            ->post(route('scripts.send-to-client', $script))
            ->assertRedirect(route('scripts.show', $script));

        $script->refresh();
        $this->assertSame(Script::STATUS_CLIENT_REVIEW, $script->status);
        $this->assertNotNull($script->sent_to_client_at);
    }

    public function test_sending_is_refused_without_the_approve_ability(): void
    {
        $client = $this->client();
        $script = $this->script($client);

        $this->actingAs($this->staff(['view', 'edit']))
            ->post(route('scripts.send-to-client', $script))
            ->assertForbidden();

        $this->assertSame(Script::STATUS_READY, $script->fresh()->status);
    }

    public function test_a_draft_script_cannot_be_sent(): void
    {
        $client = $this->client();
        $script = $this->script($client, ['status' => Script::STATUS_DRAFT]);

        $this->actingAs($this->staff())
            ->post(route('scripts.send-to-client', $script))
            ->assertNotFound();
    }

    public function test_a_script_cannot_be_sent_when_the_client_has_no_scripts_portal_section(): void
    {
        $client = $this->client(['portal_sections_disabled' => ['scripts']]);
        $script = $this->script($client);

        $this->actingAs($this->staff())
            ->post(route('scripts.send-to-client', $script))
            ->assertNotFound();

        $this->assertSame(Script::STATUS_READY, $script->fresh()->status);
    }

    // -- The client's queue --------------------------------------------------

    public function test_a_client_sees_every_pending_scripts_title(): void
    {
        $client = $this->client();
        $this->script($client, ['title' => 'Older', 'status' => Script::STATUS_CLIENT_REVIEW, 'sent_to_client_at' => now()->subDay()]);
        $this->script($client, ['title' => 'Newer', 'status' => Script::STATUS_CLIENT_REVIEW, 'sent_to_client_at' => now()]);

        $response = $this->actingAs($this->loginFor($client))->get(route('client.scripts'))
            ->assertOk()
            ->assertSee('Older')
            ->assertSee('Newer');

        $this->assertCount(2, $response->viewData('scripts'));
    }

    public function test_a_client_can_open_one_script_and_read_its_content(): void
    {
        $client = $this->client();
        $script = $this->script($client, ['status' => Script::STATUS_CLIENT_REVIEW, 'sent_to_client_at' => now()]);
        $script->sections()->create(['heading' => 'Hook', 'body' => '<p>The actual words of the script.</p>', 'position' => 0]);

        $this->actingAs($this->loginFor($client))->get(route('client.scripts.show', $script))
            ->assertOk()
            ->assertSee('The actual words of the script', false);
    }

    public function test_a_client_can_comment_without_deciding(): void
    {
        $client = $this->client();
        $script = $this->script($client, ['status' => Script::STATUS_CLIENT_REVIEW, 'sent_to_client_at' => now()]);
        $login = $this->loginFor($client);

        $this->actingAs($login)
            ->post(route('client.scripts.comment', $script), ['body' => 'Who is the voice artist for this?'])
            ->assertRedirect(route('client.scripts.show', $script).'#comments');

        $comment = $script->comments()->sole();
        $this->assertSame('Who is the voice artist for this?', $comment->body);
        $this->assertSame($login->id, $comment->user_id);

        // A comment is not a decision -- the script stays in the queue.
        $this->assertSame(Script::STATUS_CLIENT_REVIEW, $script->fresh()->status);
    }

    public function test_another_clients_script_page_404s(): void
    {
        $mine = $this->client();
        $theirs = $this->client(['name' => 'Other Brand', 'notion_venture' => 'Other']);
        $script = $this->script($theirs, ['status' => Script::STATUS_CLIENT_REVIEW, 'sent_to_client_at' => now()]);

        $this->actingAs($this->loginFor($mine))->get(route('client.scripts.show', $script))
            ->assertNotFound();
    }

    public function test_a_client_with_nothing_waiting_sees_the_empty_state(): void
    {
        $this->actingAs($this->loginFor($this->client()))->get(route('client.scripts'))
            ->assertOk()
            ->assertSee('Nothing waiting on you right now.');
    }

    public function test_another_clients_script_never_shows(): void
    {
        $mine = $this->client();
        $theirs = $this->client(['name' => 'Other Brand', 'notion_venture' => 'Other']);
        $this->script($theirs, ['title' => 'Not Yours', 'status' => Script::STATUS_CLIENT_REVIEW, 'sent_to_client_at' => now()]);

        $this->actingAs($this->loginFor($mine))->get(route('client.scripts'))
            ->assertOk()
            ->assertDontSee('Not Yours');
    }

    public function test_the_route_404s_when_the_section_is_off(): void
    {
        $client = $this->client(['portal_sections_disabled' => ['scripts']]);

        $this->actingAs($this->loginFor($client))->get(route('client.scripts'))
            ->assertNotFound();
    }

    // -- Deciding -------------------------------------------------------------

    public function test_a_client_can_approve_a_script(): void
    {
        Notification::fake();

        $client = $this->client();
        $script = $this->script($client, ['status' => Script::STATUS_CLIENT_REVIEW, 'sent_to_client_at' => now()]);
        $canSee = $this->staff(['view']);

        $this->actingAs($this->loginFor($client))
            ->post(route('client.scripts.approve', $script))
            ->assertRedirect(route('client.scripts'));

        $this->assertSame(Script::STATUS_COMPLETED, $script->fresh()->status);
        Notification::assertSentTo($canSee, ScriptApproved::class);
    }

    public function test_a_client_can_request_changes_with_a_note(): void
    {
        Notification::fake();

        $client = $this->client();
        $script = $this->script($client, ['status' => Script::STATUS_CLIENT_REVIEW, 'sent_to_client_at' => now()]);
        $canSee = $this->staff(['view']);
        $login = $this->loginFor($client);

        $this->actingAs($login)
            ->post(route('client.scripts.request-changes', $script), ['note' => 'Please make the hook punchier.'])
            ->assertRedirect(route('client.scripts'));

        $script->refresh();
        $this->assertSame(Script::STATUS_CHANGES_REQUIRED, $script->status);

        $comment = $script->comments()->sole();
        $this->assertSame('Please make the hook punchier.', $comment->body);
        $this->assertSame($login->id, $comment->user_id);

        Notification::assertSentTo($canSee, ScriptChangesRequested::class);
    }

    public function test_requesting_changes_needs_a_note(): void
    {
        $client = $this->client();
        $script = $this->script($client, ['status' => Script::STATUS_CLIENT_REVIEW, 'sent_to_client_at' => now()]);

        $this->actingAs($this->loginFor($client))
            ->post(route('client.scripts.request-changes', $script), [])
            ->assertSessionHasErrors('note');

        $this->assertSame(Script::STATUS_CLIENT_REVIEW, $script->fresh()->status);
    }

    public function test_a_client_cannot_approve_another_clients_script(): void
    {
        $mine = $this->client();
        $theirs = $this->client(['name' => 'Other Brand', 'notion_venture' => 'Other']);
        $script = $this->script($theirs, ['status' => Script::STATUS_CLIENT_REVIEW, 'sent_to_client_at' => now()]);

        $this->actingAs($this->loginFor($mine))
            ->post(route('client.scripts.approve', $script))
            ->assertNotFound();

        $this->assertSame(Script::STATUS_CLIENT_REVIEW, $script->fresh()->status);
    }

    public function test_a_script_already_decided_cannot_be_decided_again(): void
    {
        $client = $this->client();
        $script = $this->script($client, ['status' => Script::STATUS_COMPLETED, 'sent_to_client_at' => now()]);

        $this->actingAs($this->loginFor($client))
            ->post(route('client.scripts.approve', $script))
            ->assertNotFound();
    }
}
