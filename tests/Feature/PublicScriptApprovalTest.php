<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Script;
use App\Models\User;
use App\Models\UserPermission;
use App\Notifications\ScriptApproved;
use App\Notifications\ScriptChangesRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The script approval queue on a no-login link -- PublicScriptApprovalController
 * and ClientScriptApprovalLinkController, the Scripts equivalent of the brief
 * and proposal public links.
 */
class PublicScriptApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function client(array $overrides = []): Client
    {
        return Client::create(array_merge([
            'name' => 'Squad Architects',
            'portal_sections_disabled' => [],
        ], $overrides));
    }

    private function staff(array $abilities = ['view', 'edit', 'approve']): User
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $user->syncPermissions(['scripts' => $abilities]);

        return $user->refresh();
    }

    private function clientsStaff(array $abilities = ['view', 'manage']): User
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        foreach ($abilities as $ability) {
            UserPermission::create(['user_id' => $user->id, 'module' => 'clients', 'ability' => $ability]);
        }

        return $user->refresh();
    }

    private function script(Client $client, array $overrides = []): Script
    {
        $sentAt = $overrides['sent_to_client_at'] ?? null;
        unset($overrides['sent_to_client_at']);

        $script = Script::create(array_merge([
            'title' => 'Diwali reel',
            'status' => Script::STATUS_CLIENT_REVIEW,
            'priority' => Script::PRIORITY_NORMAL,
            'client_id' => $client->id,
        ], $overrides));

        if ($sentAt) {
            $script->forceFill(['sent_to_client_at' => $sentAt])->save();
        }

        return $script;
    }

    // -- Issuing the link (staff side) ---------------------------------------

    public function test_staff_with_approve_can_issue_a_link(): void
    {
        $client = $this->client();

        $this->actingAs($this->staff())
            ->post(route('clients.scripts.link', $client))
            ->assertRedirect();

        $client->refresh();
        $this->assertNotNull($client->script_approval_token);
        $this->assertNotNull($client->script_approval_token_issued_at);
    }

    public function test_issuing_a_link_creates_a_login_when_the_client_has_none(): void
    {
        $client = $this->client();
        $this->assertFalse($client->login()->exists());

        $this->actingAs($this->staff())->post(route('clients.scripts.link', $client));

        $this->assertTrue($client->login()->exists());
        $this->assertSame(User::ROLE_CLIENT, $client->login()->first()->role);
    }

    public function test_issuing_a_link_does_not_duplicate_an_existing_login(): void
    {
        $client = $this->client();
        $existing = User::factory()->create(['role' => User::ROLE_CLIENT, 'client_id' => $client->id, 'email' => 'existing@chakragroups.in']);

        $this->actingAs($this->staff())->post(route('clients.scripts.link', $client));

        $this->assertSame(1, $client->login()->count());
        $this->assertSame($existing->id, $client->login()->first()->id);
    }

    public function test_issuing_is_refused_without_the_approve_ability(): void
    {
        $client = $this->client();

        $this->actingAs($this->staff(['view', 'edit']))
            ->post(route('clients.scripts.link', $client))
            ->assertForbidden();
    }

    public function test_reissuing_replaces_the_token(): void
    {
        $client = $this->client();
        $client->issueScriptApprovalToken();
        $old = $client->script_approval_token;

        $this->actingAs($this->staff())->post(route('clients.scripts.link', $client));

        $this->assertNotSame($old, $client->fresh()->script_approval_token);
    }

    public function test_revoking_clears_the_token(): void
    {
        $client = $this->client();
        $client->issueScriptApprovalToken();

        $this->actingAs($this->staff())->delete(route('clients.scripts.link.revoke', $client));

        $this->assertNull($client->fresh()->script_approval_token);
    }

    // -- The public page ------------------------------------------------------

    public function test_the_link_lists_every_pending_scripts_title_without_login(): void
    {
        $client = $this->client();
        $client->issueScriptApprovalToken();
        $this->script($client, ['title' => 'Mutram explainer', 'sent_to_client_at' => now()]);

        $this->get(route('client.scripts.public', $client->script_approval_token))
            ->assertOk()
            ->assertSee('Mutram explainer');
    }

    public function test_opening_one_script_on_the_link_shows_its_actual_content(): void
    {
        $client = $this->client();
        $client->issueScriptApprovalToken();
        $script = $this->script($client, ['title' => 'Mutram explainer', 'sent_to_client_at' => now()]);
        $script->sections()->create(['heading' => 'Hook', 'body' => '<p>Mutram-na summa oru empty space illa.</p>', 'position' => 0]);

        // Proves the actual script content is on the page, not just the
        // title -- the exact complaint this feature exists to put to rest.
        $this->get(route('client.scripts.public.show', [$client->script_approval_token, $script]))
            ->assertOk()
            ->assertSee('Mutram explainer')
            ->assertSee('Mutram-na summa oru empty space illa', false);
    }

    public function test_an_unknown_token_is_a_404(): void
    {
        $this->get(route('client.scripts.public', 'does-not-exist'))->assertNotFound();
    }

    public function test_a_revoked_link_404s(): void
    {
        $client = $this->client();
        $client->issueScriptApprovalToken();
        $token = $client->script_approval_token;
        $client->revokeScriptApprovalToken();

        $this->get(route('client.scripts.public', $token))->assertNotFound();
    }

    public function test_commenting_on_the_link_credits_the_clients_own_login_and_does_not_decide(): void
    {
        $client = $this->client();
        $this->actingAs($this->staff())->post(route('clients.scripts.link', $client));
        $client->refresh();

        $script = $this->script($client, ['sent_to_client_at' => now()]);

        $this->post(route('client.scripts.public.comment', [$client->script_approval_token, $script]), [
            'body' => 'Who is the voice artist for this?',
        ])->assertRedirect(route('client.scripts.public.show', [$client->script_approval_token, $script]).'#comments');

        $comment = $script->comments()->sole();
        $this->assertSame('Who is the voice artist for this?', $comment->body);
        $this->assertSame($client->login()->first()->id, $comment->user_id);
        $this->assertSame(Script::STATUS_CLIENT_REVIEW, $script->fresh()->status);
    }

    // -- Deciding on the link ---------------------------------------------------

    public function test_approving_through_the_link_needs_no_login(): void
    {
        Notification::fake();

        $client = $this->client();
        $client->issueScriptApprovalToken();
        $script = $this->script($client, ['sent_to_client_at' => now()]);
        $canSee = $this->staff(['view']);

        $this->post(route('client.scripts.public.approve', [$client->script_approval_token, $script]))
            ->assertRedirect(route('client.scripts.public', $client->script_approval_token));

        $this->assertSame(Script::STATUS_COMPLETED, $script->fresh()->status);
        Notification::assertSentTo($canSee, ScriptApproved::class);
    }

    public function test_requesting_changes_through_the_link_credits_the_clients_own_login(): void
    {
        Notification::fake();

        $client = $this->client();
        $this->actingAs($this->staff())->post(route('clients.scripts.link', $client));
        $client->refresh();

        $script = $this->script($client, ['sent_to_client_at' => now()]);
        $canSee = $this->staff(['view']);

        $this->post(route('client.scripts.public.request-changes', [$client->script_approval_token, $script]), [
            'note' => 'Make the hook punchier.',
        ])->assertRedirect(route('client.scripts.public', $client->script_approval_token));

        $script->refresh();
        $this->assertSame(Script::STATUS_CHANGES_REQUIRED, $script->status);

        $comment = $script->comments()->sole();
        $this->assertSame('Make the hook punchier.', $comment->body);
        $this->assertSame($client->login()->first()->id, $comment->user_id);

        Notification::assertSentTo($canSee, ScriptChangesRequested::class);
    }

    public function test_a_script_belonging_to_another_client_cannot_be_decided_on_this_link(): void
    {
        $mine = $this->client();
        $mine->issueScriptApprovalToken();

        $theirs = $this->client(['name' => 'Other Studio']);
        $script = $this->script($theirs, ['sent_to_client_at' => now()]);

        $this->post(route('client.scripts.public.approve', [$mine->script_approval_token, $script]))
            ->assertNotFound();
    }
}
