<?php

namespace Tests\Feature;

use App\Models\Proposal;
use App\Models\ProposalComment;
use App\Models\User;
use App\Notifications\ProposalCommented;
use App\Support\ProposalBlocks;
use Database\Seeders\ProposalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProposalTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function employee(array $abilities = []): User
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        if ($abilities) {
            $user->syncPermissions(['proposals' => $abilities]);
        }

        return $user->refresh();
    }

    private function proposal(array $overrides = []): Proposal
    {
        return Proposal::create($overrides + [
            'title' => 'Test proposal',
            'status' => Proposal::STATUS_DRAFT,
            'sections' => ProposalBlocks::normalizeSections([
                ['key' => 'cover', 'type' => 'cover', 'data' => ['title' => 'Acme', 'client_name' => 'Acme']],
                ['key' => 'scope', 'type' => 'section', 'data' => [
                    'number' => '01', 'title' => 'Scope', 'new_page' => true,
                    'blocks' => [['type' => 'paragraph', 'text' => 'We will build the **thing**.']],
                ]],
            ]),
        ]);
    }

    private function sectionsJson(array $sections): string
    {
        return json_encode(ProposalBlocks::toForm(ProposalBlocks::normalizeSections($sections)));
    }

    /* ------------------------------------------------------------ admin */

    public function test_admin_can_create_view_update_and_delete_a_proposal(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('proposals.create'))->assertOk();

        $this->actingAs($admin)->post(route('proposals.store'), [
            'title' => 'Acme platform',
            'sections_json' => $this->sectionsJson([
                ['key' => 'intro', 'type' => 'section', 'data' => [
                    'number' => '01', 'title' => 'Introduction',
                    'blocks' => [['type' => 'table', 'header' => ['Module', 'Scope'], 'rows' => [['Store', 'Catalogue, cart']]]],
                ]],
            ]),
        ])->assertRedirect();

        $proposal = Proposal::firstOrFail();
        $this->assertSame($admin->id, $proposal->created_by_id);
        $this->assertSame(Proposal::STATUS_DRAFT, $proposal->status);

        // The editor's "a | b" text survived as two real cells, not one.
        $table = $proposal->normalizedSections()[0]['data']['blocks'][0];
        $this->assertSame(['Module', 'Scope'], $table['header']);
        $this->assertSame([['Store', 'Catalogue, cart']], $table['rows']);

        $this->actingAs($admin)->get(route('proposals.index'))->assertOk()->assertSee('Acme platform');
        $this->actingAs($admin)->get(route('proposals.show', $proposal))->assertOk()->assertSee('Introduction');
        $this->actingAs($admin)->get(route('proposals.edit', $proposal))->assertOk();

        $this->actingAs($admin)->put(route('proposals.update', $proposal), [
            'title' => 'Acme platform v2',
            'sections_json' => $this->sectionsJson([
                ['key' => 'intro', 'type' => 'section', 'data' => ['number' => '01', 'title' => 'Overview', 'blocks' => []]],
            ]),
        ])->assertRedirect(route('proposals.show', $proposal));

        $proposal->refresh();
        $this->assertSame('Acme platform v2', $proposal->title);
        $this->assertSame('Overview', $proposal->normalizedSections()[0]['data']['title']);

        $this->actingAs($admin)->delete(route('proposals.destroy', $proposal))->assertRedirect(route('proposals.index'));
        $this->assertModelMissing($proposal);
    }

    public function test_unreadable_sections_are_a_validation_error_not_an_empty_proposal(): void
    {
        $this->actingAs($this->admin())
            ->post(route('proposals.store'), ['title' => 'Broken', 'sections_json' => '{not json'])
            ->assertSessionHasErrors('sections_json');

        $this->assertSame(0, Proposal::count());
    }

    public function test_an_employee_without_the_module_is_refused(): void
    {
        $proposal = $this->proposal();
        $user = $this->employee();

        $this->actingAs($user)->get(route('proposals.index'))->assertForbidden();
        $this->actingAs($user)->get(route('proposals.show', $proposal))->assertForbidden();
    }

    public function test_view_only_can_read_but_not_write(): void
    {
        $proposal = $this->proposal();
        $user = $this->employee(['view']);

        $this->actingAs($user)->get(route('proposals.index'))->assertOk();
        $this->actingAs($user)->get(route('proposals.show', $proposal))->assertOk();
        $this->actingAs($user)->get(route('proposals.pdf', $proposal))->assertOk();

        $this->actingAs($user)->get(route('proposals.create'))->assertForbidden();
        $this->actingAs($user)->get(route('proposals.edit', $proposal))->assertForbidden();
        $this->actingAs($user)->post(route('proposals.link', $proposal))->assertForbidden();
        $this->actingAs($user)->post(route('proposals.duplicate', $proposal))->assertForbidden();
        $this->actingAs($user)->delete(route('proposals.destroy', $proposal))->assertForbidden();

        $comment = $proposal->comments()->create(['author_name' => 'Client', 'body' => 'Hi']);
        $this->actingAs($user)->post(route('proposals.comments.resolve', [$proposal, $comment]))->assertForbidden();
    }

    public function test_duplicate_is_a_fresh_draft_without_link_or_comments(): void
    {
        $proposal = $this->proposal();
        $proposal->issuePublicToken();
        $proposal->comments()->create(['author_name' => 'Client', 'body' => 'Hi']);

        $this->actingAs($this->admin())->post(route('proposals.duplicate', $proposal))->assertRedirect();

        $copy = Proposal::whereKeyNot($proposal->id)->firstOrFail();
        $this->assertSame('Test proposal (copy)', $copy->title);
        $this->assertSame(Proposal::STATUS_DRAFT, $copy->status);
        $this->assertNull($copy->public_token);
        $this->assertSame(0, $copy->comments()->count());
        $this->assertEquals($proposal->normalizedSections(), $copy->normalizedSections());
    }

    public function test_status_can_be_set_by_an_editor(): void
    {
        $proposal = $this->proposal();

        $this->actingAs($this->admin())
            ->patch(route('proposals.status', $proposal), ['status' => Proposal::STATUS_ACCEPTED])
            ->assertRedirect();

        $this->assertSame(Proposal::STATUS_ACCEPTED, $proposal->refresh()->status);

        $this->actingAs($this->admin())
            ->patch(route('proposals.status', $proposal), ['status' => 'bogus'])
            ->assertSessionHasErrors('status');
    }

    /* ----------------------------------------------------- share link */

    public function test_issuing_a_link_sends_a_draft_and_revoking_it_404s(): void
    {
        $proposal = $this->proposal();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('proposals.link', $proposal))->assertRedirect();
        $proposal->refresh();
        $token = $proposal->public_token;

        $this->assertNotNull($token);
        $this->assertSame(48, strlen($token));
        $this->assertSame(Proposal::STATUS_SENT, $proposal->status);

        $this->get(route('proposals.public', $token))->assertOk()->assertSee('Scope');

        // Reissuing kills the old URL.
        $this->actingAs($admin)->post(route('proposals.link', $proposal));
        auth()->logout();
        $this->get(route('proposals.public', $token))->assertNotFound();

        $this->actingAs($admin)->delete(route('proposals.link.revoke', $proposal));
        auth()->logout();
        $this->assertNull($proposal->refresh()->public_token);
        $this->get(route('proposals.public', $token))->assertNotFound();
    }

    public function test_an_unknown_token_is_a_404(): void
    {
        $this->get('/p/not-a-real-token')->assertNotFound();
        $this->get('/p/not-a-real-token/pdf')->assertNotFound();
        $this->post('/p/not-a-real-token/comments', ['author_name' => 'X', 'body' => 'Y'])->assertNotFound();
    }

    public function test_the_first_client_view_marks_it_viewed_but_staff_views_do_not(): void
    {
        $proposal = $this->proposal();
        $token = $proposal->issuePublicToken();

        $this->actingAs($this->admin())->get(route('proposals.public', $token))->assertOk();
        $this->assertSame(Proposal::STATUS_SENT, $proposal->refresh()->status);
        $this->assertNull($proposal->first_viewed_at);

        auth()->logout();
        $this->get(route('proposals.public', $token))->assertOk();
        $proposal->refresh();
        $this->assertSame(Proposal::STATUS_VIEWED, $proposal->status);
        $this->assertNotNull($proposal->first_viewed_at);
    }

    public function test_a_view_does_not_undo_a_decision(): void
    {
        $proposal = $this->proposal();
        $token = $proposal->issuePublicToken();
        $proposal->forceFill(['status' => Proposal::STATUS_ACCEPTED])->save();

        $this->get(route('proposals.public', $token))->assertOk();

        $this->assertSame(Proposal::STATUS_ACCEPTED, $proposal->refresh()->status);
    }

    /* ------------------------------------------------ public comments */

    public function test_a_client_can_comment_on_a_section_and_the_creator_is_notified(): void
    {
        Notification::fake();

        $creator = $this->admin();
        $proposal = $this->proposal(['created_by_id' => $creator->id]);
        $token = $proposal->issuePublicToken();

        $this->post(route('proposals.public.comment', $token), [
            'author_name' => 'Ravi',
            'author_email' => 'ravi@example.com',
            'body' => 'Can we add WhatsApp updates?',
            'section_key' => 'scope',
        ])->assertRedirect(route('proposals.public', $token).'#section-scope');

        $comment = ProposalComment::firstOrFail();
        $this->assertSame('scope', $comment->section_key);
        $this->assertNull($comment->user_id);
        $this->assertSame('Ravi', $comment->author_name);

        Notification::assertSentTo($creator, ProposalCommented::class);

        $this->get(route('proposals.public', $token))->assertSee('Can we add WhatsApp updates?');
        $this->actingAs($creator)->get(route('proposals.show', $proposal))->assertSee('Can we add WhatsApp updates?');
    }

    public function test_an_unknown_section_key_becomes_general_feedback(): void
    {
        $proposal = $this->proposal();
        $token = $proposal->issuePublicToken();

        $this->post(route('proposals.public.comment', $token), [
            'author_name' => 'Ravi', 'body' => 'An idea', 'section_key' => 'made-up',
        ])->assertRedirect(route('proposals.public', $token).'#feedback');

        $this->assertNull(ProposalComment::firstOrFail()->section_key);
    }

    public function test_public_comments_are_validated(): void
    {
        $proposal = $this->proposal();
        $token = $proposal->issuePublicToken();

        $this->post(route('proposals.public.comment', $token), ['author_name' => '', 'body' => '', 'author_email' => 'nope'])
            ->assertSessionHasErrors(['author_name', 'body', 'author_email']);

        $this->assertSame(0, ProposalComment::count());
    }

    public function test_the_honeypot_stores_nothing(): void
    {
        $proposal = $this->proposal();
        $token = $proposal->issuePublicToken();

        $this->post(route('proposals.public.comment', $token), [
            'author_name' => 'Bot', 'body' => 'Buy things', 'website' => 'http://spam.example',
        ])->assertRedirect();

        $this->assertSame(0, ProposalComment::count());
    }

    public function test_a_reply_cannot_attach_to_another_proposals_comment(): void
    {
        $mine = $this->proposal();
        $theirs = $this->proposal(['title' => 'Other']);
        $foreign = $theirs->comments()->create(['author_name' => 'Someone', 'body' => 'Private']);
        $token = $mine->issuePublicToken();

        $this->post(route('proposals.public.comment', $token), [
            'author_name' => 'Ravi', 'body' => 'Reply', 'parent_id' => $foreign->id,
        ])->assertNotFound();

        $this->assertSame(1, ProposalComment::count());
    }

    public function test_public_comments_are_throttled(): void
    {
        $proposal = $this->proposal();
        $token = $proposal->issuePublicToken();

        for ($i = 0; $i < 30; $i++) {
            $this->post(route('proposals.public.comment', $token), ['author_name' => 'R', 'body' => 'n'.$i]);
        }

        $this->post(route('proposals.public.comment', $token), ['author_name' => 'R', 'body' => 'one too many'])
            ->assertStatus(429);
    }

    /* ------------------------------------------------- staff replies */

    public function test_staff_can_reply_resolve_and_reopen(): void
    {
        $proposal = $this->proposal();
        $token = $proposal->issuePublicToken();
        $comment = $proposal->comments()->create(['section_key' => 'scope', 'author_name' => 'Ravi', 'body' => 'Question']);
        $staff = $this->employee(['view', 'comment']);

        $this->actingAs($staff)->post(route('proposals.comments.store', $proposal), [
            'body' => 'Yes, in Phase 2.', 'parent_id' => $comment->id,
        ])->assertRedirect();

        $reply = ProposalComment::whereNotNull('parent_id')->firstOrFail();
        $this->assertSame($staff->id, $reply->user_id);
        $this->assertSame('scope', $reply->section_key);

        $this->actingAs($staff)->post(route('proposals.comments.resolve', [$proposal, $comment]))->assertRedirect();
        $this->assertNotNull($comment->refresh()->resolved_at);
        $this->assertSame($staff->id, $comment->resolved_by_id);

        $this->actingAs($staff)->delete(route('proposals.comments.reopen', [$proposal, $comment]))->assertRedirect();
        $this->assertNull($comment->refresh()->resolved_at);

        // The client sees the studio's answer on their link.
        auth()->logout();
        $this->get(route('proposals.public', $token))->assertSee('Yes, in Phase 2.');
    }

    public function test_a_comment_from_another_proposal_cannot_be_resolved_through_this_one(): void
    {
        $proposal = $this->proposal();
        $other = $this->proposal(['title' => 'Other']);
        $comment = $other->comments()->create(['author_name' => 'X', 'body' => 'Y']);

        $this->actingAs($this->admin())
            ->post(route('proposals.comments.resolve', [$proposal, $comment]))
            ->assertNotFound();
    }

    public function test_a_client_reply_reopens_a_resolved_thread(): void
    {
        Notification::fake();

        $proposal = $this->proposal();
        $token = $proposal->issuePublicToken();
        $comment = $proposal->comments()->create(['author_name' => 'Ravi', 'body' => 'Q']);
        $comment->forceFill(['resolved_at' => now()])->save();

        $this->post(route('proposals.public.comment', $token), [
            'author_name' => 'Ravi', 'body' => 'One more thing', 'parent_id' => $comment->id,
        ])->assertRedirect();

        $this->assertNull($comment->refresh()->resolved_at);
    }

    public function test_open_client_comments_appear_in_the_creators_bell_until_resolved(): void
    {
        $creator = $this->admin();
        $proposal = $this->proposal(['created_by_id' => $creator->id]);
        $comment = $proposal->comments()->create(['author_name' => 'Ravi', 'body' => 'Bell me']);

        $this->actingAs($creator)->getJson(route('notification-center.feed'))
            ->assertOk()
            ->assertJsonFragment(['type' => 'proposal-comment', 'subtitle' => 'Bell me']);

        $comment->forceFill(['resolved_at' => now()])->save();

        $this->actingAs($creator)->getJson(route('notification-center.feed'))
            ->assertJsonMissing(['type' => 'proposal-comment']);
    }

    /* ------------------------------------------------------------- pdf */

    public function test_the_pdf_downloads_for_staff_and_on_the_public_link(): void
    {
        $proposal = $this->proposal();
        $token = $proposal->issuePublicToken();

        $this->actingAs($this->admin())->get(route('proposals.pdf', $proposal))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        auth()->logout();
        $this->get(route('proposals.public-pdf', $token))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    /* ---------------------------------------------------------- blocks */

    public function test_every_block_survives_the_editor_round_trip(): void
    {
        $sections = ProposalBlocks::normalizeSections(ProposalSeeder::sections());

        $posted = json_decode(json_encode(ProposalBlocks::toForm($sections)), true);

        $this->assertSame($sections, ProposalBlocks::fromForm($posted));
    }

    public function test_inline_marks_are_escaped_before_formatting(): void
    {
        $this->assertSame(
            '&lt;script&gt; <strong>bold</strong> <strong class="cp-ok">ok</strong>',
            ProposalBlocks::inline('<script> **bold** [ok]ok[/ok]')
        );
    }

    public function test_a_cover_logo_cannot_point_outside_proposal_art(): void
    {
        $cover = ProposalBlocks::normalizeCover(['client_logo' => '../.env']);
        $this->assertNull($cover['client_logo']);

        $cover = ProposalBlocks::normalizeCover(['client_logo' => 'images/chakra-logo.png']);
        $this->assertNull($cover['client_logo']);

        $cover = ProposalBlocks::normalizeCover(['client_logo' => 'images/proposals/print-bazzar.png']);
        $this->assertSame('images/proposals/print-bazzar.png', $cover['client_logo']);
    }

    /* ---------------------------------------------------------- seeder */

    public function test_the_print_bazzar_seeder_is_complete_and_idempotent(): void
    {
        $admin = $this->admin();

        $this->seed(ProposalSeeder::class);
        $this->seed(ProposalSeeder::class);

        $this->assertSame(1, Proposal::count());
        $proposal = Proposal::firstOrFail();

        // Every block type is in use, so this is a complete template.
        $used = collect($proposal->normalizedSections())
            ->flatMap(fn ($s) => $s['data']['blocks'] ?? [])
            ->pluck('type')
            ->unique();
        $this->assertEqualsCanonicalizing(array_keys(ProposalBlocks::BLOCK_TYPES), $used->all());

        // 22 sheets, like the design: the cover plus 21 new-page breaks.
        $this->assertCount(22, ProposalBlocks::sheets($proposal->normalizedSections()));

        $this->actingAs($admin)->get(route('proposals.show', $proposal))
            ->assertOk()
            ->assertSee('Order Flow Map')
            ->assertSee('22 / 22');
    }
}
