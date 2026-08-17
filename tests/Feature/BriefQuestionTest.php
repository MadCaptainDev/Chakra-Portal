<?php

namespace Tests\Feature;

use App\Models\BriefQuestion;
use App\Models\Client;
use App\Models\User;
use App\Support\BrandBrief;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Questions the studio adds itself, from Setup → Brief Questions.
 *
 * The point of the screen is that one question added there is asked of every
 * client, so most of this is about that reaching the actual form -- a question
 * that saves but never appears is the failure nobody notices until a client
 * says they were never asked.
 */
class BriefQuestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The catalogue is cached per request; these tests create questions
        // and then read it back within one.
        BrandBrief::flush();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'step_id' => 'business',
            'type' => BrandBrief::TYPE_TEXT,
            'label' => 'Do you have parking at the shop?',
        ];
    }

    public function test_an_admin_can_add_a_question(): void
    {
        $this->actingAs($this->admin())
            ->post(route('brief-questions.store'), $this->payload())
            ->assertRedirect();

        $question = BriefQuestion::sole();

        $this->assertSame('Do you have parking at the shop?', $question->label);
        $this->assertSame('business', $question->step_id);
        $this->assertTrue($question->is_active);
    }

    public function test_a_new_question_reaches_every_clients_brief(): void
    {
        $this->actingAs($this->admin())->post(route('brief-questions.store'), $this->payload());

        BrandBrief::flush();

        // In the catalogue...
        $this->assertArrayHasKey('do_you_have_parking_at_the_shop', BrandBrief::questions());

        // ...and on the form a client actually opens.
        $client = Client::create(['name' => 'SVA Silks']);
        $token = $client->brief()->create([])->issuePublicToken();

        $this->get(route('brief.public', $token))
            ->assertOk()
            ->assertSee('Do you have parking at the shop?');
    }

    public function test_a_client_can_answer_a_studio_added_question(): void
    {
        $this->actingAs($this->admin())->post(route('brief-questions.store'), $this->payload());
        BrandBrief::flush();

        $client = Client::create(['name' => 'SVA Silks']);
        $token = $client->brief()->create([])->issuePublicToken();

        $this->post(route('brief.public.update', $token), [
            'answers' => ['do_you_have_parking_at_the_shop' => 'Yes, six spaces.'],
        ])->assertRedirect();

        $this->assertSame(
            'Yes, six spaces.',
            $client->brief->fresh()->load('answers')->answer('do_you_have_parking_at_the_shop')
        );
    }

    public function test_a_list_question_offers_its_options_and_refuses_anything_else(): void
    {
        $this->actingAs($this->admin())->post(route('brief-questions.store'), $this->payload([
            'type' => BrandBrief::TYPE_CHIPS,
            'label' => 'Do you have a showroom?',
            'options' => "Yes\nNo\nComing soon",
        ]));
        BrandBrief::flush();

        $this->assertSame(['Yes', 'No', 'Coming soon'], BrandBrief::optionsFor('do_you_have_a_showroom'));

        $client = Client::create(['name' => 'SVA Silks']);
        $token = $client->brief()->create([])->issuePublicToken();

        // The chips are the whole vocabulary of the question.
        $this->post(route('brief.public.update', $token), [
            'answers' => ['do_you_have_a_showroom' => 'Maybe'],
        ])->assertSessionHasErrors('answers.do_you_have_a_showroom');
    }

    public function test_a_list_question_needs_at_least_one_option(): void
    {
        $this->actingAs($this->admin())
            ->post(route('brief-questions.store'), $this->payload([
                'type' => BrandBrief::TYPE_CHIPS,
                'options' => "  \n  ",
            ]))
            ->assertSessionHasErrors('options');

        $this->assertSame(0, BriefQuestion::count());
    }

    public function test_renaming_a_question_keeps_the_answers_attached(): void
    {
        $this->actingAs($this->admin())->post(route('brief-questions.store'), $this->payload());
        BrandBrief::flush();

        $client = Client::create(['name' => 'SVA Silks']);
        $token = $client->brief()->create([])->issuePublicToken();
        $this->post(route('brief.public.update', $token), [
            'answers' => ['do_you_have_parking_at_the_shop' => 'Yes, six spaces.'],
        ]);

        $question = BriefQuestion::sole();

        $this->actingAs($this->admin())->put(route('brief-questions.update', $question), $this->payload([
            'label' => 'Is there parking for customers?',
        ]));
        BrandBrief::flush();

        // The key was fixed at creation, so a reworded label keeps the answer.
        $this->assertSame('do_you_have_parking_at_the_shop', $question->fresh()->key);
        $this->assertSame(
            'Yes, six spaces.',
            $client->brief->fresh()->load('answers')->answer('do_you_have_parking_at_the_shop')
        );
    }

    public function test_archiving_hides_the_question_but_keeps_the_answers(): void
    {
        $this->actingAs($this->admin())->post(route('brief-questions.store'), $this->payload());
        BrandBrief::flush();

        $client = Client::create(['name' => 'SVA Silks']);
        $token = $client->brief()->create([])->issuePublicToken();
        $this->post(route('brief.public.update', $token), [
            'answers' => ['do_you_have_parking_at_the_shop' => 'Yes, six spaces.'],
        ]);

        $this->actingAs($this->admin())->delete(route('brief-questions.destroy', BriefQuestion::sole()));
        BrandBrief::flush();

        // Gone from the form...
        $this->assertArrayNotHasKey('do_you_have_parking_at_the_shop', BrandBrief::questions());

        // ...but the client's answer survives, which is the whole reason this
        // archives rather than deletes.
        $this->assertSame(
            'Yes, six spaces.',
            $client->brief->fresh()->load('answers')->answer('do_you_have_parking_at_the_shop')
        );
    }

    public function test_a_custom_question_never_collides_with_a_built_in_key(): void
    {
        // "about" is a code-catalogue key; a studio question slugging to it
        // would silently share that question's answers.
        $this->actingAs($this->admin())->post(route('brief-questions.store'), $this->payload([
            'label' => 'About',
        ]));

        $this->assertNotSame('about', BriefQuestion::sole()->key);
    }

    public function test_two_questions_with_the_same_label_get_different_keys(): void
    {
        $this->actingAs($this->admin())->post(route('brief-questions.store'), $this->payload());
        $this->actingAs($this->admin())->post(route('brief-questions.store'), $this->payload());

        $this->assertCount(2, BriefQuestion::pluck('key')->unique());
    }

    public function test_a_required_custom_question_blocks_submitting(): void
    {
        $this->actingAs($this->admin())->post(route('brief-questions.store'), $this->payload([
            'required' => '1',
        ]));
        BrandBrief::flush();

        $this->assertContains('do_you_have_parking_at_the_shop', BrandBrief::requiredKeys());
    }

    public function test_only_an_admin_reaches_the_screen(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))
            ->get(route('brief-questions.index'))
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->get(route('brief-questions.index'))
            ->assertOk()
            ->assertSee('Brand Brief Questions');
    }
    public function test_a_clients_own_question_is_asked_only_of_them(): void
    {
        $zira = Client::create(['name' => 'Zira Bridal Studio']);
        $other = Client::create(['name' => 'SVA Silks']);

        BriefQuestion::create([
            'client_id' => $zira->id,
            'step_id' => BriefQuestion::stepIdFor($zira->id),
            'group_label' => 'Your craft',
            'type' => BrandBrief::TYPE_TEXTAREA,
            'label' => 'Who inspired you to enter the makeup industry?',
        ]);

        // Hers, on her form...
        $hers = $zira->brief()->create([])->issuePublicToken();
        $this->get(route('brief.public', $hers))
            ->assertOk()
            ->assertSee('Who inspired you to enter the makeup industry?')
            ->assertSee('Your craft');

        // ...and nowhere near anybody else's.
        $theirs = $other->brief()->create([])->issuePublicToken();
        $this->get(route('brief.public', $theirs))
            ->assertOk()
            ->assertDontSee('Who inspired you to enter the makeup industry?')
            ->assertDontSee('Your craft');
    }

    public function test_switching_client_does_not_leak_the_previous_ones_questions(): void
    {
        $zira = Client::create(['name' => 'Zira Bridal Studio']);
        $other = Client::create(['name' => 'SVA Silks']);

        BriefQuestion::create([
            'client_id' => $zira->id,
            'step_id' => BriefQuestion::stepIdFor($zira->id),
            'group_label' => 'Your craft',
            'type' => BrandBrief::TYPE_TEXT,
            'label' => 'What is your biggest USP?',
        ]);

        // The catalogue is static and request-cached, so a page rendering two
        // clients in turn is exactly where a leak would show up.
        BrandBrief::forClient($zira);
        $this->assertArrayHasKey('what_is_your_biggest_usp', BrandBrief::questions());

        BrandBrief::forClient($other);
        $this->assertArrayNotHasKey('what_is_your_biggest_usp', BrandBrief::questions());

        BrandBrief::forClient(null);
        $this->assertArrayNotHasKey('what_is_your_biggest_usp', BrandBrief::questions());
    }

    public function test_a_private_question_does_not_change_another_clients_progress(): void
    {
        $zira = Client::create(['name' => 'Zira Bridal Studio']);
        $other = Client::create(['name' => 'SVA Silks']);

        BriefQuestion::create([
            'client_id' => $zira->id,
            'step_id' => BriefQuestion::stepIdFor($zira->id),
            'group_label' => 'Your craft',
            'type' => BrandBrief::TYPE_TEXT,
            'label' => 'What do you want to be known for?',
            'required' => true,
        ]);

        $hers = $zira->brief()->create([]);
        $theirs = $other->brief()->create([]);

        BrandBrief::forClient($zira);
        $ziraTotal = $hers->requiredTotal();

        BrandBrief::forClient($other);
        $otherTotal = $theirs->requiredTotal();

        // Her extra required question counts for her and for nobody else.
        $this->assertSame($otherTotal + 1, $ziraTotal);
    }
}
