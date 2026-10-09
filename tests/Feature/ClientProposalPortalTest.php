<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientProposalPortalTest extends TestCase
{
    use RefreshDatabase;

    private function client(string $name = 'SVA Silks'): Client
    {
        return Client::create(['name' => $name]);
    }

    private function loginFor(Client $client): User
    {
        return User::factory()->create(['role' => User::ROLE_CLIENT, 'client_id' => $client->id]);
    }

    private function proposal(Client $client, string $status = Proposal::STATUS_SENT, string $title = 'Growth plan'): Proposal
    {
        return Proposal::create([
            'client_id' => $client->id,
            'title' => $title,
            'status' => $status,
            'sections' => [['key' => 'scope', 'type' => 'section', 'data' => [
                'number' => '01', 'title' => 'Scope',
                'blocks' => [['type' => 'paragraph', 'text' => 'Twelve reels a month.']],
            ]]],
        ]);
    }

    public function test_a_client_sees_their_sent_proposals_but_not_drafts_or_others(): void
    {
        $mine = $this->client();
        $this->proposal($mine, title: 'Growth plan');
        $this->proposal($mine, Proposal::STATUS_DRAFT, 'Half-written');
        $this->proposal($this->client('Someone else'), title: 'Not yours');

        $this->actingAs($this->loginFor($mine))->get(route('client.proposals'))
            ->assertOk()
            ->assertSee('Growth plan')
            ->assertDontSee('Half-written')
            ->assertDontSee('Not yours');
    }

    public function test_the_detail_page_shows_the_document_and_marks_it_viewed(): void
    {
        $client = $this->client();
        $proposal = $this->proposal($client);

        $this->actingAs($this->loginFor($client))->get(route('client.proposals.show', $proposal->id))
            ->assertOk()
            ->assertSee('Growth plan')
            ->assertSee('Scope')
            ->assertSee(route('client.proposals.comment', $proposal->id), false)
            ->assertDontSee('name="author_name"', false);

        $this->assertSame(Proposal::STATUS_VIEWED, $proposal->fresh()->status);
    }

    public function test_drafts_and_other_clients_proposals_are_a_404(): void
    {
        $mine = $this->client();
        $login = $this->loginFor($mine);
        $draft = $this->proposal($mine, Proposal::STATUS_DRAFT);
        $theirs = $this->proposal($this->client('Someone else'));

        foreach ([$draft, $theirs] as $proposal) {
            $this->actingAs($login)->get(route('client.proposals.show', $proposal->id))->assertNotFound();
            $this->actingAs($login)->get(route('client.proposals.pdf', $proposal->id))->assertNotFound();
            $this->actingAs($login)->post(route('client.proposals.comment', $proposal->id), ['body' => 'Hi'])->assertNotFound();
        }
    }

    public function test_a_signed_in_client_comments_under_their_own_name_not_as_team(): void
    {
        $client = $this->client();
        $login = $this->loginFor($client);
        $proposal = $this->proposal($client);

        $this->actingAs($login)
            ->post(route('client.proposals.comment', $proposal->id), [
                'body' => 'Can we add stories?',
                'section_key' => 'scope',
                'author_name' => 'Someone pretending',
            ])
            ->assertRedirect(route('client.proposals.show', $proposal->id).'#section-scope');

        $comment = $proposal->comments()->sole();
        $this->assertSame($login->name, $comment->author_name);
        $this->assertSame('scope', $comment->section_key);
        $this->assertFalse($comment->isFromStaff());
    }

    public function test_the_section_can_be_switched_off_per_client(): void
    {
        $client = $this->client();
        $client->forceFill(['portal_sections_disabled' => ['proposals']])->save();

        $this->actingAs($this->loginFor($client))->get(route('client.proposals'))->assertNotFound();
    }
}
