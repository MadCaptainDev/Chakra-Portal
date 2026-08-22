<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\CompetitorAccount;
use App\Models\CompetitorReel;
use App\Models\CompetitorReelAnalysis;
use App\Models\CompetitorSetting;
use App\Models\GeneratedConcept;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The web side of the competitor-analysis module: tracking accounts,
 * triggering a scrape, and generating concepts from an analyzed reel.
 */
class CompetitorAccountControllerTest extends TestCase
{
    use RefreshDatabase;

    private function staff(array $abilities = ['view', 'create', 'delete']): User
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $user->syncPermissions(['competitors' => $abilities]);

        return $user->refresh();
    }

    public function test_an_ungranted_employee_cannot_reach_the_screen(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($user)->get(route('competitors.index'))->assertForbidden();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get(route('competitors.index'))->assertRedirect(route('login'));
    }

    public function test_a_granted_staff_member_can_track_a_new_competitor(): void
    {
        $client = Client::create(['name' => 'SVA Silks']);

        $this->actingAs($this->staff())->post(route('competitors.store'), [
            'username' => '@some_competitor',
            'client_id' => $client->id,
        ])->assertRedirect(route('competitors.index'));

        $account = CompetitorAccount::firstOrFail();
        // The leading @ is stripped, not rejected.
        $this->assertSame('some_competitor', $account->username);
        $this->assertSame($client->id, $account->client_id);
    }

    public function test_tracking_the_same_handle_twice_does_not_duplicate_it(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->post(route('competitors.store'), ['username' => 'same_one']);
        $this->actingAs($staff)->post(route('competitors.store'), ['username' => 'same_one']);

        $this->assertSame(1, CompetitorAccount::count());
    }

    public function test_scraping_without_an_apify_token_is_refused_with_a_friendly_message(): void
    {
        $account = CompetitorAccount::create(['username' => 'a_competitor', 'platform' => 'instagram']);

        $response = $this->actingAs($this->staff())->post(route('competitors.scrape', $account));

        $response->assertRedirect();
        $response->assertSessionHas('status', fn ($status) => str_contains($status, 'Apify token'));
    }

    public function test_scraping_stores_reels_and_updates_the_accounts_average(): void
    {
        CompetitorSetting::current()->update(['apify_token' => 'token-123']);
        $account = CompetitorAccount::create(['username' => 'a_competitor', 'platform' => 'instagram']);

        Http::fake(['api.apify.com/*' => Http::response([
            ['url' => 'https://www.instagram.com/p/1/', 'videoUrl' => 'https://cdn/vid1.mp4', 'videoPlayCount' => 8000, 'timestamp' => now()->toIso8601String()],
        ], 200)]);

        $this->actingAs($this->staff())->post(route('competitors.scrape', $account))->assertRedirect();

        $this->assertSame(1, $account->reels()->count());
        $this->assertNotNull($account->fresh()->last_scraped_at);
    }

    public function test_the_show_screen_lists_reels_highest_views_first(): void
    {
        $account = CompetitorAccount::create(['username' => 'a_competitor', 'platform' => 'instagram']);
        $low = CompetitorReel::create(['competitor_account_id' => $account->id, 'platform_post_id' => 'p1', 'play_count' => 100, 'scraped_at' => now()]);
        $high = CompetitorReel::create(['competitor_account_id' => $account->id, 'platform_post_id' => 'p2', 'play_count' => 900, 'scraped_at' => now()]);

        $response = $this->actingAs($this->staff())->get(route('competitors.show', $account));

        $response->assertOk();
        $ids = $response->viewData('reels')->pluck('id');
        $this->assertSame([$high->id, $low->id], $ids->all());
    }

    public function test_removing_a_tracked_competitor_deletes_its_reels_too(): void
    {
        $account = CompetitorAccount::create(['username' => 'a_competitor', 'platform' => 'instagram']);
        CompetitorReel::create(['competitor_account_id' => $account->id, 'platform_post_id' => 'p1', 'scraped_at' => now()]);

        $this->actingAs($this->staff())->delete(route('competitors.destroy', $account))->assertRedirect();

        $this->assertSame(0, CompetitorAccount::count());
        $this->assertSame(0, CompetitorReel::count());
    }

    public function test_generating_concepts_without_an_anthropic_key_is_refused(): void
    {
        $analysis = $this->analyzedReel();

        $response = $this->actingAs($this->staff())->post(
            route('competitor-reel-analyses.generate-concepts', $analysis),
            ['brand_prompt' => 'Adapt for a jewellery brand.'],
        );

        $response->assertSessionHas('status', fn ($status) => str_contains($status, 'Anthropic API key'));
        $this->assertSame(0, GeneratedConcept::count());
    }

    public function test_generating_concepts_stores_claudes_reply_against_a_client(): void
    {
        CompetitorSetting::current()->update(['anthropic_api_key' => 'sk-ant-test']);
        $client = Client::create(['name' => 'SVA Silks']);
        $analysis = $this->analyzedReel();

        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Concept: open on a close-up, then reveal the product.']],
        ], 200)]);

        $this->actingAs($this->staff())->post(route('competitor-reel-analyses.generate-concepts', $analysis), [
            'client_id' => $client->id,
            'brand_prompt' => 'Adapt for a jewellery brand, warm tone.',
        ])->assertRedirect();

        $concept = GeneratedConcept::firstOrFail();
        $this->assertSame($client->id, $concept->client_id);
        $this->assertSame('Concept: open on a close-up, then reveal the product.', $concept->concept_text);
        $this->assertSame('Adapt for a jewellery brand, warm tone.', $concept->brand_prompt);
    }

    private function analyzedReel(): CompetitorReelAnalysis
    {
        $account = CompetitorAccount::create(['username' => 'a_competitor', 'platform' => 'instagram']);
        $reel = CompetitorReel::create(['competitor_account_id' => $account->id, 'platform_post_id' => 'p1', 'scraped_at' => now()]);

        return CompetitorReelAnalysis::create([
            'competitor_reel_id' => $reel->id,
            'breakdown' => 'A shot-by-shot breakdown.',
            'gemini_model' => 'gemini-2.5-flash',
            'analyzed_at' => now(),
        ]);
    }
}
