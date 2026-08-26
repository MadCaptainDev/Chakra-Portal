<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PortfolioItem;
use App\Models\SocialAccount;
use App\Models\SocialInsight;
use App\Models\SocialMediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A video already added to the Portfolio must not be addable again as a
 * second piece -- the picker hides/disables it, and validated()'s unique
 * rule against portfolio_items.social_media_item_id is what a request that
 * bypasses the picker actually has to get past.
 */
class PortfolioDuplicatePreventionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function client(string $name = 'Chakra Production'): Client
    {
        return Client::create(['name' => $name]);
    }

    private static int $nextPlatformUserId = 27841476964090891;

    private function connectedAccount(?Client $client = null): SocialAccount
    {
        $client ??= $this->client();
        $account = SocialAccount::create([
            'client_id' => $client->id,
            'platform' => SocialAccount::PLATFORM_INSTAGRAM,
            'platform_user_id' => (string) self::$nextPlatformUserId++,
            'username' => 'client_'.$client->id,
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);

        $account->forceFill(['access_token' => 'IGQV-token', 'connected_at' => now(), 'last_synced_at' => now()->subHour()])->save();

        return $account->fresh();
    }

    private function media(SocialAccount $account, string $platformMediaId = '18000000000000099'): SocialMediaItem
    {
        $media = SocialMediaItem::create([
            'social_account_id' => $account->id,
            'platform_media_id' => $platformMediaId,
            'media_type' => SocialMediaItem::TYPE_VIDEO,
            'media_product_type' => SocialMediaItem::PRODUCT_REELS,
            'caption' => 'A real caption',
            'permalink' => 'https://www.instagram.com/p/CxAbCdEfGh/',
            'posted_at' => now()->subDays(2),
            'cached_at' => now(),
        ]);

        SocialInsight::record([
            'social_account_id' => $account->id,
            'social_media_item_id' => $media->id,
            'metric' => 'views',
            'metric_type' => SocialInsight::TYPE_TOTAL_VALUE,
            'value' => 5000,
            'period' => 'lifetime',
            'period_start' => now()->toDateString(),
        ]);

        return $media->fresh();
    }

    public function test_the_same_instagram_post_cannot_become_two_portfolio_pieces(): void
    {
        $account = $this->connectedAccount();
        $media = $this->media($account);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('portfolio.store'), [
            'title' => 'First piece', 'client_id' => $account->client_id,
            'social_media_item_id' => $media->id, 'is_visible' => '1',
        ])->assertRedirect(route('portfolio.index'));

        $this->assertSame(1, PortfolioItem::count());

        $response = $this->actingAs($admin)->post(route('portfolio.store'), [
            'title' => 'Second piece, same video', 'client_id' => $account->client_id,
            'social_media_item_id' => $media->id, 'is_visible' => '1',
        ]);

        $response->assertSessionHasErrors('social_media_item_id');
        $this->assertSame(1, PortfolioItem::count());
    }

    public function test_the_media_picker_marks_an_already_linked_post_as_already_added(): void
    {
        $account = $this->connectedAccount();
        $media = $this->media($account);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('portfolio.store'), [
            'title' => 'First piece', 'client_id' => $account->client_id,
            'social_media_item_id' => $media->id, 'is_visible' => '1',
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('portfolio.instagram-media', ['client_id' => $account->client_id]))
            ->assertOk();

        $response->assertJsonFragment(['id' => $media->id, 'already_added' => true]);
    }

    public function test_the_media_picker_does_not_flag_unmapped_posts(): void
    {
        $account = $this->connectedAccount();
        $media = $this->media($account);

        $response = $this->actingAs($this->admin())
            ->getJson(route('portfolio.instagram-media', ['client_id' => $account->client_id]))
            ->assertOk();

        $response->assertJsonFragment(['id' => $media->id, 'already_added' => false]);
    }

    /**
     * Editing a piece and re-saving it against its OWN existing mapping
     * must not trip the duplicate check against itself.
     */
    public function test_saving_a_piece_against_its_own_existing_mapping_is_not_a_duplicate(): void
    {
        $account = $this->connectedAccount();
        $media = $this->media($account);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('portfolio.store'), [
            'title' => 'A piece', 'client_id' => $account->client_id,
            'social_media_item_id' => $media->id, 'is_visible' => '1',
        ]);
        $item = PortfolioItem::firstOrFail();

        $response = $this->actingAs($admin)->put(route('portfolio.update', $item), [
            'title' => 'A piece, retitled', 'client_id' => $account->client_id,
            'social_media_item_id' => $media->id, 'is_visible' => '1',
        ]);

        $response->assertSessionDoesntHaveErrors('social_media_item_id');
        $this->assertSame('A piece, retitled', $item->fresh()->title);
    }

    /**
     * The picker excludes the item being edited from "already_added" --
     * otherwise a piece's own mapping would flag itself as taken and the
     * edit screen would show its own linked post as unavailable.
     */
    public function test_the_media_picker_excludes_the_current_item_being_edited(): void
    {
        $account = $this->connectedAccount();
        $media = $this->media($account);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('portfolio.store'), [
            'title' => 'A piece', 'client_id' => $account->client_id,
            'social_media_item_id' => $media->id, 'is_visible' => '1',
        ]);
        $item = PortfolioItem::firstOrFail();

        $response = $this->actingAs($admin)->getJson(
            route('portfolio.instagram-media', ['client_id' => $account->client_id, 'exclude_item_id' => $item->id])
        )->assertOk();

        $response->assertJsonFragment(['id' => $media->id, 'already_added' => false]);
    }
}
