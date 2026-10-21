<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContentAccount;
use App\Models\ContentAccountVenture;
use App\Models\ContentItem;
use App\Models\SocialAccount;
use App\Models\SocialInsight;
use App\Models\SocialMediaItem;
use App\Models\User;
use App\Models\WhatsappSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReelPerformanceNudgeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    /**
     * @param  array<int, int|null>  $viewsPerReel  one published August reel each; null = no synced post
     * @return array{0: ContentAccount, 1: array<int, ContentItem>}
     */
    private function accountWithReels(array $viewsPerReel, ?string $phone = '7094126823'): array
    {
        $client = Client::create(['name' => 'Janet', 'phone' => $phone]);
        $account = ContentAccount::create(['client_id' => $client->id, 'name' => 'Janet Hospitals', 'target_reel' => 10]);
        ContentAccountVenture::create(['content_account_id' => $account->id, 'venture' => 'Janet']);

        $social = SocialAccount::create([
            'client_id' => $client->id,
            'platform' => 'instagram',
            'platform_user_id' => '1',
        ]);

        $items = [];

        foreach ($viewsPerReel as $i => $views) {
            $media = null;

            if ($views !== null) {
                $media = SocialMediaItem::create([
                    'social_account_id' => $social->id,
                    'platform_media_id' => 'm'.$i,
                    'permalink' => 'https://instagram.com/p/m'.$i,
                ]);
                SocialInsight::create([
                    'social_account_id' => $social->id,
                    'social_media_item_id' => $media->id,
                    'metric' => 'views',
                    'dedupe_key' => 'views-'.$media->id,
                    'fetched_at' => now(),
                    'value' => $views,
                    'period' => 'lifetime',
                    'period_start' => '2026-08-20',
                    'period_end' => '2026-08-20',
                ]);
            }

            $items[] = ContentItem::factory()->create([
                'source' => ContentItem::SOURCE_REEL,
                'venture' => 'Janet',
                'status' => 'Published',
                'published_date' => '2026-08-0'.($i + 1),
                'title' => 'Reel '.$i,
                'social_media_item_id' => $media?->id,
            ]);
        }

        return [$account, $items];
    }

    private function whatsappConfigured(): void
    {
        WhatsappSetting::current()->update([
            'access_token' => 'EAAG-test-token',
            'phone_number_id' => '556677889900',
        ]);
    }

    public function test_only_a_reel_well_above_the_accounts_usual_views_is_flagged_with_a_nudge_button(): void
    {
        [$account, $items] = $this->accountWithReels([1200, 1500, 9000]);

        $response = $this->actingAs($this->admin())
            ->get(route('content-dashboard.show', [$account, 'month' => '2026-08']))
            ->assertOk()
            ->assertSee(route('content-dashboard.nudge', [$account, $items[2]]), false)
            ->assertDontSee(route('content-dashboard.nudge', [$account, $items[0]]), false)
            ->assertDontSee(route('content-dashboard.nudge', [$account, $items[1]]), false);

        $this->assertSame(1, substr_count($response->getContent(), 'Doing well'));
    }

    public function test_nothing_is_flagged_below_the_view_floor(): void
    {
        [$account] = $this->accountWithReels([100, 150, 400]);

        $this->actingAs($this->admin())
            ->get(route('content-dashboard.show', [$account, 'month' => '2026-08']))
            ->assertOk()
            ->assertDontSee('Doing well');
    }

    public function test_nudge_sends_the_client_a_whatsapp_naming_the_video_and_its_views(): void
    {
        $this->whatsappConfigured();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.GOOD1']]])]);

        [$account, $items] = $this->accountWithReels([1200, 9000]);

        $this->actingAs($this->admin())
            ->post(route('content-dashboard.nudge', [$account, $items[1]]))
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $body['type'] === 'template'
                && $body['template']['name'] === 'reel_performing_well_v1'
                && $body['template']['components'][0]['parameters'][0]['text'] === 'Janet'
                && $body['template']['components'][0]['parameters'][1]['text'] === 'Reel 1'
                && $body['template']['components'][0]['parameters'][2]['text'] === '9,000';
        });
    }

    public function test_nudge_refuses_a_client_with_no_phone_and_sends_nothing(): void
    {
        Http::fake();

        [$account, $items] = $this->accountWithReels([1200, 9000], phone: null);

        $this->actingAs($this->admin())
            ->post(route('content-dashboard.nudge', [$account, $items[1]]))
            ->assertSessionHas('error');

        Http::assertNothingSent();
    }

    public function test_nudge_will_not_send_for_an_item_from_another_accounts_venture(): void
    {
        Http::fake();

        [$account] = $this->accountWithReels([1200, 9000]);
        $stranger = ContentItem::factory()->create(['venture' => 'Someone Else', 'source' => ContentItem::SOURCE_REEL]);

        $this->actingAs($this->admin())
            ->post(route('content-dashboard.nudge', [$account, $stranger]))
            ->assertNotFound();

        Http::assertNothingSent();
    }
}
