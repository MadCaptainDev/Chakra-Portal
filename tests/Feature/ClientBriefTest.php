<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientBrief;
use App\Models\ClientBriefAnswer;
use App\Models\Invoice;
use App\Models\Script;
use App\Models\TaxonomyTerm;
use App\Models\User;
use App\Support\BrandBrief;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientBriefTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    private function client(array $overrides = []): Client
    {
        return Client::create(array_merge(['name' => 'SVA Silks'], $overrides));
    }

    private function loginFor(Client $client, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role' => User::ROLE_CLIENT,
            'client_id' => $client->id,
        ], $overrides));
    }

    private function staff(array $permissions = []): User
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $user->syncPermissions($permissions);

        return $user->fresh();
    }

    private function term(string $type, string $name, bool $active = true): TaxonomyTerm
    {
        return TaxonomyTerm::create([
            'type' => $type,
            'name' => $name,
            'slug' => TaxonomyTerm::uniqueSlug($type, $name),
            'is_active' => $active,
        ]);
    }

    /** A brief with the given answers already stored. */
    private function answered(Client $client, array $answers, bool $submitted = false): ClientBrief
    {
        $brief = ClientBrief::create([
            'client_id' => $client->id,
            'status' => $submitted ? ClientBrief::STATUS_SUBMITTED : ClientBrief::STATUS_IN_PROGRESS,
        ]);

        if ($submitted) {
            $brief->forceFill(['submitted_at' => now()])->save();
        }

        foreach ($answers as $key => $value) {
            ClientBriefAnswer::create([
                'client_brief_id' => $brief->id,
                'question_key' => $key,
                'value' => is_array($value) ? null : $value,
                'value_json' => is_array($value) ? $value : null,
            ]);
        }

        return $brief->fresh('answers');
    }

    /**
     * A complete required set, ready to submit. Built from the catalogue so
     * that adding a required question breaks this helper rather than silently
     * weakening every submit test that uses it.
     */
    private function completePayload(): array
    {
        $answers = [];

        foreach (BrandBrief::requiredKeys() as $key) {
            $question = BrandBrief::question($key);

            $answers[$key] = match ($question['type']) {
                BrandBrief::TYPE_CONTACT => ['name' => 'Vinupriya', 'phone' => '+91 90000 00000'],
                BrandBrief::TYPE_CHIPS, BrandBrief::TYPE_CHECKS => ($question['multi'] ?? false)
                    ? [$question['options'][0]]
                    : $question['options'][0],
                default => 'An answer for ' . $key,
            };
        }

        return $answers;
    }

    // -- Authorization ----------------------------------------------------

    public function test_a_client_sees_only_their_own_brief(): void
    {
        $mine = $this->client(['name' => 'Mine']);
        $theirs = $this->client(['name' => 'Theirs']);

        $this->answered($mine, ['about' => 'We weave our own silk']);
        $this->answered($theirs, ['about' => 'A secret that is not mine to read']);

        $this->actingAs($this->loginFor($mine))
            ->get(route('client.brief'))
            ->assertOk()
            ->assertSee('We weave our own silk')
            ->assertDontSee('A secret that is not mine to read');
    }

    /**
     * The guard that matters most. There is no {client} in any brief path, and
     * this pins that: whatever a client posts, only their own rows move.
     */
    public function test_a_client_saving_never_writes_to_another_clients_brief(): void
    {
        $mine = $this->client(['name' => 'Mine']);
        $theirs = $this->client(['name' => 'Theirs']);
        $other = $this->answered($theirs, ['about' => 'Untouched']);

        $this->actingAs($this->loginFor($mine))
            ->post(route('client.brief.update'), [
                'answers' => ['about' => 'Mine only', 'client_id' => $theirs->id],
            ])
            ->assertRedirect(route('client.brief'));

        $this->assertSame('Untouched', $other->answers()->where('question_key', 'about')->value('value'));
        $this->assertSame('Mine only', $mine->fresh()->brief->answer('about'));
        $this->assertSame(2, ClientBrief::count());
    }

    public function test_an_employee_cannot_reach_the_brief_screens(): void
    {
        $this->actingAs($this->staff());

        $this->get(route('client.brief'))->assertForbidden();
        $this->post(route('client.brief.update'), ['answers' => []])->assertForbidden();
        $this->post(route('client.brief.submit'), ['answers' => []])->assertForbidden();
    }

    /** An admin has no client_id. Deliberate, and mirrors ClientPortalTest. */
    public function test_an_admin_cannot_reach_the_brief_screens(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->get(route('client.brief'))
            ->assertForbidden();
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(route('client.brief'))->assertRedirect(route('login'));
    }

    public function test_a_client_login_with_no_client_is_refused_cleanly(): void
    {
        $orphan = User::factory()->create(['role' => User::ROLE_CLIENT, 'client_id' => null]);

        $this->actingAs($orphan)->get(route('client.brief'))->assertForbidden();
    }

    public function test_staff_without_the_clients_module_cannot_read_a_brief(): void
    {
        $client = $this->client();
        $this->answered($client, ['about' => 'We weave our own silk']);

        $this->actingAs($this->staff(['scripts' => ['view']]))
            ->get(route('clients.show', $client))
            ->assertForbidden();

        $this->actingAs($this->staff(['clients' => ['view']]))
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertSee('We weave our own silk');
    }

    /**
     * The deliberate second gate: the brief exists for the writer, and a
     * writer holds scripts.*, not clients.*.
     */
    public function test_a_writer_sees_the_brief_on_the_script_editor_without_the_clients_module(): void
    {
        $client = $this->client();
        $this->answered($client, ['idealCustomer' => 'Brides in Coimbatore']);
        $script = Script::create(['title' => 'Diwali film', 'client_id' => $client->id, 'status' => Script::STATUS_DRAFT, 'priority' => Script::PRIORITY_NORMAL]);

        $this->actingAs($this->staff(['scripts' => ['view', 'edit']]))
            ->get(route('scripts.edit', $script))
            ->assertOk()
            ->assertSee('Brides in Coimbatore');
    }

    public function test_the_full_brief_link_is_hidden_from_a_writer_without_clients_view(): void
    {
        $client = $this->client();
        $this->answered($client, ['idealCustomer' => 'Brides in Coimbatore']);
        $script = Script::create(['title' => 'Diwali film', 'client_id' => $client->id, 'status' => Script::STATUS_DRAFT, 'priority' => Script::PRIORITY_NORMAL]);

        $this->actingAs($this->staff(['scripts' => ['view', 'edit']]))
            ->get(route('scripts.edit', $script))
            ->assertDontSee(route('clients.show', $client));
    }

    public function test_the_script_drawer_never_shows_another_clients_brief(): void
    {
        $subject = $this->client(['name' => 'Subject']);
        $other = $this->client(['name' => 'Other']);

        $this->answered($subject, ['idealCustomer' => 'Brides in Coimbatore']);
        $this->answered($other, ['idealCustomer' => 'Nobody else should read this']);

        $script = Script::create(['title' => 'Diwali film', 'client_id' => $subject->id, 'status' => Script::STATUS_DRAFT, 'priority' => Script::PRIORITY_NORMAL]);

        $this->actingAs($this->staff(['scripts' => ['view', 'edit']]))
            ->get(route('scripts.edit', $script))
            ->assertSee('Brides in Coimbatore')
            ->assertDontSee('Nobody else should read this');
    }

    // -- Behaviour --------------------------------------------------------

    public function test_saving_a_draft_does_not_demand_the_required_questions(): void
    {
        $client = $this->client();

        $this->actingAs($this->loginFor($client))
            ->post(route('client.brief.update'), ['answers' => ['about' => 'Only this one']])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('client.brief'));

        $this->assertSame(ClientBrief::STATUS_IN_PROGRESS, $client->fresh()->brief->status);
    }

    public function test_submitting_an_incomplete_brief_is_refused(): void
    {
        $client = $this->client();
        $payload = $this->completePayload();
        unset($payload['about']);

        $this->actingAs($this->loginFor($client))
            ->post(route('client.brief.submit'), ['answers' => $payload])
            ->assertSessionHasErrors('answers.about');

        // Nothing was stored, so the brief has not even started.
        $this->assertNull($client->fresh()->brief);
    }

    public function test_submitting_a_complete_brief_stamps_who_and_when(): void
    {
        $client = $this->client();
        $login = $this->loginFor($client);

        $this->actingAs($login)
            ->post(route('client.brief.submit'), ['answers' => $this->completePayload()])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('client.dashboard'));

        $brief = $client->fresh()->brief;

        $this->assertSame(ClientBrief::STATUS_SUBMITTED, $brief->status);
        $this->assertNotNull($brief->submitted_at);
        $this->assertSame($login->id, $brief->submitted_by_id);
    }

    public function test_a_submitted_brief_can_still_be_edited_and_keeps_its_first_submitted_at(): void
    {
        $client = $this->client();
        $login = $this->loginFor($client);

        $this->actingAs($login)->post(route('client.brief.submit'), ['answers' => $this->completePayload()]);
        $firstSubmit = $client->fresh()->brief->submitted_at;

        $this->travel(1)->days();

        $this->actingAs($login)
            ->post(route('client.brief.update'), ['answers' => ['about' => 'We changed our mind']])
            ->assertSessionHasNoErrors();

        $brief = $client->fresh()->brief;

        $this->assertTrue($firstSubmit->equalTo($brief->submitted_at));
        $this->assertSame(ClientBrief::STATUS_SUBMITTED, $brief->status);
        $this->assertSame('We changed our mind', $brief->answer('about'));
        $this->assertTrue($brief->editedSinceSubmit('about'));
    }

    /** Pins the unique index and the upsert that depends on it. */
    public function test_an_answer_is_updated_not_duplicated(): void
    {
        $client = $this->client();
        $login = $this->loginFor($client);

        $this->actingAs($login)->post(route('client.brief.update'), ['answers' => ['about' => 'First']]);
        $this->actingAs($login)->post(route('client.brief.update'), ['answers' => ['about' => 'Second']]);

        $this->assertSame(1, ClientBriefAnswer::where('question_key', 'about')->count());
        $this->assertSame('Second', $client->fresh()->brief->answer('about'));
    }

    public function test_a_chip_answer_must_come_from_its_own_list(): void
    {
        $client = $this->client();

        // The chips are the whole vocabulary of the question. Accepting a
        // value that was never offered would let anything through the one
        // control on the form that is supposed to be closed.
        $this->actingAs($this->loginFor($client))
            ->post(route('client.brief.update'), [
                'answers' => ['perception' => ['Premium', 'Not An Option']],
            ])
            ->assertSessionHasErrors('answers.perception.1');

        $this->assertSame(0, ClientBriefAnswer::count());
    }

    public function test_a_multi_chip_stores_every_choice(): void
    {
        $client = $this->client();
        $picked = array_slice(BrandBrief::optionsFor('perception'), 0, 3);

        $this->actingAs($this->loginFor($client))
            ->post(route('client.brief.update'), ['answers' => ['perception' => $picked]])
            ->assertSessionHasNoErrors();

        $this->assertSame($picked, $client->fresh()->brief->answer('perception'));
    }

    public function test_an_unknown_question_key_is_dropped_rather_than_stored(): void
    {
        $client = $this->client();

        $this->actingAs($this->loginFor($client))
            ->post(route('client.brief.update'), [
                'answers' => ['about' => 'Real', 'evil_key' => 'Should never land'],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, ClientBriefAnswer::where('question_key', 'evil_key')->count());
        $this->assertSame(1, ClientBriefAnswer::count());
    }

    public function test_progress_counts_only_the_required_questions(): void
    {
        $client = $this->client();

        // Six optional answers, none of them required.
        $brief = $this->answered($client, [
            'hero' => 'Bridal sarees',
            'competitors' => 'Three generations',
            'locations' => 'Coimbatore',
            'tagline' => 'Woven with care',
            'competitors' => 'Two others',
            'default_cta' => 'Visit the store',
        ]);

        $this->assertSame(0, $brief->requiredAnswered());
        $this->assertSame(count(BrandBrief::requiredKeys()), $brief->requiredTotal());
        $this->assertFalse($brief->isComplete());
    }

    public function test_a_cleared_answer_does_not_count_as_answered(): void
    {
        $client = $this->client();
        $login = $this->loginFor($client);

        $this->actingAs($login)->post(route('client.brief.update'), ['answers' => ['about' => 'Something']]);
        $this->assertSame(1, $client->fresh()->brief->requiredAnswered());

        $this->actingAs($login)->post(route('client.brief.update'), ['answers' => ['about' => '   ']]);
        $this->assertSame(0, $client->fresh()->brief->requiredAnswered());
    }

    public function test_an_unanswered_brief_prompts_on_the_client_dashboard(): void
    {
        $client = $this->client();

        $this->actingAs($this->loginFor($client))
            ->get(route('client.dashboard'))
            ->assertOk()
            ->assertSee('Before we write for you');
    }

    public function test_a_submitted_brief_no_longer_prompts(): void
    {
        $client = $this->client();
        $this->answered($client, ['about' => 'Done'], submitted: true);

        $this->actingAs($this->loginFor($client))
            ->get(route('client.dashboard'))
            ->assertOk()
            ->assertDontSee('Before we write for you');
    }

    /** Nothing writes on a GET, from either side. */
    public function test_opening_a_brief_creates_no_rows(): void
    {
        $client = $this->client();

        $this->actingAs($this->loginFor($client))->get(route('client.brief'))->assertOk();
        $this->actingAs($this->staff(['clients' => ['view']]))->get(route('clients.show', $client))->assertOk();

        $this->assertSame(0, ClientBrief::count());
        $this->assertSame(0, ClientBriefAnswer::count());
    }

    /**
     * These are plain-text answers rendered with {{ }} and never sanitised
     * HTML. This is the test that keeps it that way.
     */
    public function test_an_answer_is_escaped_not_rendered(): void
    {
        $client = $this->client();
        $this->answered($client, ['about' => '<script>alert(1)</script>']);

        $this->actingAs($this->staff(['clients' => ['view']]))
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', escape: false)
            ->assertSee('&lt;script&gt;', escape: false);
    }

    /**
     * The same guard ClientPortalTest keeps on the PDF route: nothing in the
     * client area may sit inside recurring.catchup, or a client opening a page
     * issues the studio's monthly invoices to everybody else.
     */
    public function test_the_brief_screens_do_not_generate_the_studios_invoices(): void
    {
        $client = $this->client();
        $login = $this->loginFor($client);

        $before = Invoice::count();

        $this->actingAs($login)->get(route('client.brief'))->assertOk();
        $this->actingAs($login)->post(route('client.brief.update'), ['answers' => ['about' => 'Anything']]);

        $this->assertSame($before, Invoice::count());
    }
}
