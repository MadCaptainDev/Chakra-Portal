<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientBrief;
use App\Models\User;
use App\Models\UserPermission;
use App\Support\BrandBrief;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The brand brief filled through a one-time link, by a client with no login.
 *
 * The token is the only credential these routes have, so most of this is about
 * what the token does not open.
 */
class PublicBriefLinkTest extends TestCase
{
    use RefreshDatabase;

    private function client(string $name = 'SVA Silks'): Client
    {
        return Client::create(['name' => $name]);
    }

    private function staff(array $abilities = ['view', 'edit']): User
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        foreach ($abilities as $ability) {
            UserPermission::create(['user_id' => $user->id, 'module' => 'clients', 'ability' => $ability]);
        }

        return $user->refresh();
    }

    /**
     * Every required question, answered validly.
     *
     * Built from the catalogue rather than hardcoded: chips must be answered
     * with one of their own options, so a question added later is answered
     * here without anybody editing this list.
     *
     * @return array<string, mixed>
     */
    private function completeAnswers(): array
    {
        $answers = [];

        foreach (BrandBrief::requiredKeys() as $key) {
            $question = BrandBrief::question($key);

            $answers[$key] = match ($question['type']) {
                BrandBrief::TYPE_CONTACT => ['name' => 'Vinupriya', 'phone' => '+91 90000 00000'],
                BrandBrief::TYPE_CHIPS, BrandBrief::TYPE_CHECKS => ($question['multi'] ?? false)
                    ? [$question['options'][0]]
                    : $question['options'][0],
                default => 'An answer.',
            };
        }

        return $answers;
    }

    public function test_a_link_opens_the_form_for_someone_with_no_login(): void
    {
        $client = $this->client();
        $brief = $client->brief()->create([]);
        $token = $brief->issuePublicToken();

        $this->get(route('brief.public', $token))
            ->assertOk()
            ->assertSee('Brand brief')
            ->assertSee($client->name);
    }

    public function test_an_unknown_token_is_a_404_rather_than_a_hint(): void
    {
        // Not a 403: there is nothing to authenticate as, so "no" and "wrong"
        // must look identical or a guessed token gets feedback.
        $this->get(route('brief.public', 'nope-not-a-real-token'))->assertNotFound();
    }

    public function test_answers_save_without_submitting(): void
    {
        $client = $this->client();
        $token = $client->brief()->create([])->issuePublicToken();

        $this->post(route('brief.public.update', $token), [
            'answers' => ['about' => 'We sell sarees.'],
        ])->assertRedirect(route('brief.public', $token));

        $brief = $client->brief->fresh()->load('answers');

        $this->assertSame('We sell sarees.', $brief->answer('about'));
        $this->assertFalse($brief->isSubmitted());
        // Still open, so the client can come back to the same link.
        $this->assertTrue($brief->acceptsPublicEdits());
    }

    public function test_submitting_closes_the_link_for_good(): void
    {
        $client = $this->client();
        $token = $client->brief()->create([])->issuePublicToken();

        $this->post(route('brief.public.submit', $token), [
            'answers' => $this->completeAnswers(),
            'submitted_name' => 'Vinupriya',
        ])->assertRedirect(route('brief.public', $token));

        $brief = $client->brief->fresh();

        $this->assertTrue($brief->isSubmitted());
        $this->assertSame('Vinupriya', $brief->public_submitted_name);
        $this->assertNotNull($brief->public_submitted_at);
        $this->assertFalse($brief->acceptsPublicEdits());
    }

    public function test_a_used_link_shows_a_thank_you_rather_than_the_form(): void
    {
        $client = $this->client();
        $brief = $client->brief()->create([]);
        $token = $brief->issuePublicToken();
        $brief->forceFill(['status' => ClientBrief::STATUS_SUBMITTED, 'submitted_at' => now()])->save();

        $this->get(route('brief.public', $token))
            ->assertOk()
            ->assertSee('Thank you')
            ->assertDontSee('Save &amp; continue');
    }

    public function test_a_stale_tab_cannot_write_over_a_submitted_brief(): void
    {
        $client = $this->client();
        $brief = $client->brief()->create([]);
        $token = $brief->issuePublicToken();

        $this->post(route('brief.public.update', $token), [
            'answers' => ['about' => 'The real answer.'],
        ]);

        $brief->forceFill(['status' => ClientBrief::STATUS_SUBMITTED, 'submitted_at' => now()])->save();

        // The link is closed, so a form left open overnight is refused rather
        // than silently overwriting what was sent in.
        $this->post(route('brief.public.update', $token), [
            'answers' => ['about' => 'Overwritten later.'],
        ])->assertForbidden();

        $this->assertSame('The real answer.', $client->brief->fresh()->load('answers')->answer('about'));
    }

    public function test_reissuing_kills_the_previous_link(): void
    {
        $client = $this->client();
        $brief = $client->brief()->create([]);
        $old = $brief->issuePublicToken();
        $new = $brief->fresh()->issuePublicToken();

        $this->assertNotSame($old, $new);
        $this->get(route('brief.public', $old))->assertNotFound();
        $this->get(route('brief.public', $new))->assertOk();
    }

    public function test_one_clients_token_never_opens_another_clients_brief(): void
    {
        $sva = $this->client('SVA Silks');
        $thor = $this->client('Thor Gym');

        $token = $sva->brief()->create([])->issuePublicToken();
        $thor->brief()->create([])->issuePublicToken();

        $this->get(route('brief.public', $token))
            ->assertOk()
            ->assertSee('SVA Silks')
            ->assertDontSee('Thor Gym');
    }

    public function test_staff_can_issue_and_close_a_link(): void
    {
        $client = $this->client();
        $staff = $this->staff();

        $this->actingAs($staff)->post(route('clients.brief.link', $client))->assertRedirect();
        $this->assertNotNull($client->brief->fresh()->public_token);

        $this->actingAs($staff)->delete(route('clients.brief.link.revoke', $client))->assertRedirect();
        $this->assertNull($client->brief->fresh()->public_token);
    }

    public function test_issuing_a_link_needs_edit_not_merely_view(): void
    {
        $client = $this->client();

        $this->actingAs($this->staff(['view']))
            ->post(route('clients.brief.link', $client))
            ->assertForbidden();
    }

    public function test_reopening_lets_the_client_back_in(): void
    {
        $client = $this->client();
        $brief = $client->brief()->create([]);
        $token = $brief->issuePublicToken();
        $brief->forceFill(['status' => ClientBrief::STATUS_SUBMITTED, 'submitted_at' => now()])->save();

        $this->actingAs($this->staff())->post(route('clients.brief.reopen', $client))->assertRedirect();

        $this->assertFalse($client->brief->fresh()->isSubmitted());
        // The wizard is back, rather than the thank-you page.
        $this->get(route('brief.public', $token))
            ->assertOk()
            ->assertSee('Review your information')
            ->assertDontSee('Thank you');
    }

    public function test_the_brief_exports_as_readable_text(): void
    {
        $client = $this->client();
        $brief = $client->brief()->create([]);
        $brief->answers()->create(['question_key' => 'about', 'value' => 'We sell sarees in Trichy.']);

        $response = $this->actingAs($this->staff(['view']))
            ->get(route('clients.brief.export', $client));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/plain; charset=UTF-8');
        $this->assertStringContainsString('attachment; filename="sva-silks-brand-brief.txt"',
            $response->headers->get('content-disposition'));

        $text = $response->getContent();
        $this->assertStringContainsString('BRAND BRIEF — SVA Silks', $text);
        $this->assertStringContainsString('We sell sarees in Trichy.', $text);
    }

    public function test_the_export_is_not_public(): void
    {
        $client = $this->client();
        $client->brief()->create([]);

        $this->get(route('clients.brief.export', $client))->assertRedirect(route('login'));
    }

    // -- CSRF exemption on the public routes ---------------------------------

    /**
     * The regression this suite's ordinary POST tests above cannot catch:
     * Laravel's ValidateCsrfToken middleware skips verification for EVERY
     * request in the testing environment (see VerifyCsrfToken::runningUnitTests()),
     * so `$this->post(...)` succeeding here proves nothing about whether the
     * route itself carries the exemption -- only the route DEFINITION does,
     * which is what these assert directly.
     *
     * Real bug this is coverage for: a client (Thillai Pets Clinic) filled
     * out a full brand brief that was never saved anywhere. The wizard
     * autosaves via fetch() every ~900ms of typing; once the session
     * (SESSION_LIFETIME=120 minutes) expired mid-fill, every autosave AND
     * the final submit started failing with 419, and fetch() does not treat
     * an HTTP error status as a failure, so every autosave failure was
     * completely silent. The public routes' only real credential is the long
     * random token already in the URL -- session-based CSRF was protecting
     * nothing here that the token's own secrecy doesn't already cover, while
     * actively breaking any brief that took longer to fill than one session
     * lifetime.
     */
    public function test_the_public_update_and_submit_routes_are_exempt_from_csrf(): void
    {
        $update = Route::getRoutes()->getByName('brief.public.update');
        $submit = Route::getRoutes()->getByName('brief.public.submit');

        $this->assertContains(ValidateCsrfToken::class, $update->excludedMiddleware());
        $this->assertContains(ValidateCsrfToken::class, $submit->excludedMiddleware());
    }

    /**
     * The exemption is attached to these two route objects directly rather
     * than a URI-pattern except() in bootstrap/app.php specifically so it
     * cannot leak onto the logged-in client portal's OWN brief/submit
     * routes, which sit at URIs a wildcard pattern like 'brief/*' would also
     * match. Those routes are reached through a real authenticated session
     * and should keep full CSRF protection -- this is the regression test
     * for that leak never happening.
     */
    public function test_the_authenticated_portal_brief_routes_still_require_csrf(): void
    {
        $update = Route::getRoutes()->getByName('client.brief.update');
        $submit = Route::getRoutes()->getByName('client.brief.submit');

        $this->assertNotContains(ValidateCsrfToken::class, $update->excludedMiddleware());
        $this->assertNotContains(ValidateCsrfToken::class, $submit->excludedMiddleware());
    }
}
