<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\InstagramSetting;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\Instagram\InstagramGraph;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Connecting a client's Instagram account.
 *
 * The callback cannot carry a {client} -- Meta allows one exact redirect URI --
 * so the client comes from the session. Most of this is about that holding:
 * nothing a browser sends may decide whose account a connection becomes.
 */
class InstagramConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = 'AQBx-instagram-auth-code';

    protected function setUp(): void
    {
        parent::setUp();

        InstagramSetting::current()->update([
            'app_id' => '1122334455',
            'app_secret' => 'instagram-app-secret',
        ]);
    }

    private function staff(array $abilities = ['view', 'manage']): User
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        foreach ($abilities as $ability) {
            UserPermission::create(['user_id' => $user->id, 'module' => 'clients', 'ability' => $ability]);
        }

        return $user->refresh();
    }

    private function client(string $name = 'DJ Thanga Maligai'): Client
    {
        return Client::create(['name' => $name]);
    }

    /** Instagram answering the whole exchange happily. */
    private function fakeInstagram(string $username = 'djthangamaligai', string $userId = '17841400000000001'): void
    {
        Http::fake([
            'api.instagram.com/oauth/access_token' => Http::response([
                'access_token' => 'IGQV-short-lived',
                'user_id' => $userId,
                'permissions' => ['instagram_business_basic', 'instagram_business_manage_insights'],
            ]),
            'graph.instagram.com/access_token*' => Http::response([
                'access_token' => 'IGQV-long-lived',
                'token_type' => 'bearer',
                'expires_in' => 5184000,
            ]),
            'graph.instagram.com/*/me*' => Http::response([
                'user_id' => $userId,
                'username' => $username,
                'account_type' => 'BUSINESS',
                'followers_count' => 4210,
                'media_count' => 312,
                // A realistic-length signed CDN URL. The first live connection
                // (thechakra_productions) failed here: the column was 500
                // chars and Instagram's real URLs run past 600. Left long on
                // purpose so a regression in the column width fails loudly
                // instead of only in production.
                'profile_picture_url' => 'https://scontent-bom5-1.cdninstagram.com/v/t51.82787-19/'
                    .'560346598_17850158463565470_8731637826199421232_n.jpg?stp=dst-jpg_s206x206_tt6'
                    .'&_nc_cat=111&ccb=7-5&_nc_sid=bf7eb4&efg=eyJ2ZW5jb2RlX3RhZyI6InByb2ZpbGVfcGljLnd3dy4xMDI0LkMzIn0'
                    .'&_nc_ohc=7VX1Og4LyLoQ7kNvwGjFT6w&_nc_oc=AdphbtNZ9P5t68U9BATLGygKKw5qXcQ-BiM4CB8SzLLxlkxxDsc1oCiwzUerapEyI7IImiXPitoHScUKS3GnXJym'
                    .'&_nc_zt=24&_nc_ht=scontent-bom5-1.cdninstagram.com&edm=AP4hL3IEAAAA'
                    .'&_nc_gid=0mB33WPuVf4FpKwQo83WqA&_nc_tpa=Q5bMBQLYbDk6xz8bgOE3-PlwNgb5b-c-2mjsYXWaKWk8Gw4gfFqNytM0ozvPVJ-D1j-RWc9il0ohx7A8Bw'
                    .'&oh=00_AQGyNFDkp4R8d4J6QT3UfrYkEpDU3p2_7IMQ8zeRJmwDbw&oe=6A88ED00',
            ]),
        ]);
    }

    /** Start a connection and return the state the app put in the session. */
    private function beginConnect(User $staff, Client $client): string
    {
        $this->actingAs($staff)
            ->post(route('instagram.connect', $client))
            ->assertRedirectContains(InstagramGraph::AUTHORIZE_URL);

        return session('instagram.oauth')['state'];
    }

    public function test_connecting_sends_the_client_to_instagram_with_a_state(): void
    {
        $client = $this->client();

        $response = $this->actingAs($this->staff())->post(route('instagram.connect', $client));

        $target = $response->headers->get('Location');

        $this->assertStringStartsWith(InstagramGraph::AUTHORIZE_URL, $target);
        $this->assertStringContainsString('client_id=1122334455', $target);
        $this->assertStringContainsString('response_type=code', $target);
        // Insights is requested now so nobody is dragged back through a second
        // consent screen when Phase 3 lands.
        $this->assertStringContainsString('instagram_business_manage_insights', $target);
        $this->assertStringContainsString(urlencode(InstagramSetting::current()->callbackUrl()), $target);

        // The client id is in the session, never in the URL.
        $this->assertSame($client->id, session('instagram.oauth')['client_id']);
        $this->assertStringNotContainsString('client_id='.$client->id.'&', $target);
    }

    public function test_a_completed_callback_stores_the_connection(): void
    {
        $staff = $this->staff();
        $client = $this->client();
        $this->fakeInstagram();

        $state = $this->beginConnect($staff, $client);

        $this->actingAs($staff)
            ->get(route('instagram.callback', ['code' => self::CODE, 'state' => $state]))
            ->assertRedirect(route('clients.show', $client));

        $account = SocialAccount::sole();

        $this->assertSame($client->id, $account->client_id);
        $this->assertSame(SocialAccount::PLATFORM_INSTAGRAM, $account->platform);
        $this->assertSame('djthangamaligai', $account->username);
        $this->assertSame('BUSINESS', $account->account_type);
        $this->assertSame(4210, $account->followers_count);
        $this->assertTrue($account->isConnected());
        // The long-lived token, never the one-hour one.
        $this->assertSame('IGQV-long-lived', $account->access_token);
        $this->assertTrue($account->token_expires_at->isFuture());
    }

    public function test_the_stored_token_is_encrypted_at_rest(): void
    {
        $staff = $this->staff();
        $client = $this->client();
        $this->fakeInstagram();
        $state = $this->beginConnect($staff, $client);

        $this->actingAs($staff)->get(route('instagram.callback', ['code' => self::CODE, 'state' => $state]));

        $raw = \DB::table('social_accounts')->value('access_token');

        $this->assertNotSame('IGQV-long-lived', $raw);
        $this->assertStringNotContainsString('IGQV', $raw);
    }

    public function test_a_callback_with_the_wrong_state_is_refused(): void
    {
        $staff = $this->staff();
        $client = $this->client();
        $this->fakeInstagram();
        $this->beginConnect($staff, $client);

        $this->actingAs($staff)
            ->get(route('instagram.callback', ['code' => self::CODE, 'state' => 'not-the-state']))
            ->assertRedirect(route('clients.index'));

        $this->assertSame(0, SocialAccount::count());
    }

    public function test_a_callback_with_no_pending_attempt_is_refused(): void
    {
        // A bare hit on the callback, with no button ever pressed.
        $this->actingAs($this->staff())
            ->get(route('instagram.callback', ['code' => self::CODE, 'state' => 'invented']))
            ->assertRedirect(route('clients.index'));

        $this->assertSame(0, SocialAccount::count());
    }

    public function test_a_client_id_in_the_callback_url_is_ignored(): void
    {
        $staff = $this->staff();
        $mine = $this->client('DJ Thanga Maligai');
        $theirs = $this->client('SVA Silks');
        $this->fakeInstagram();

        $state = $this->beginConnect($staff, $mine);

        /*
         * The attack this design exists to stop: the session says one client,
         * the URL claims another. The session must win, or one client's
         * Instagram account lands on another client's record.
         */
        $this->actingAs($staff)->get(route('instagram.callback', [
            'code' => self::CODE,
            'state' => $state,
            'client' => $theirs->id,
            'client_id' => $theirs->id,
        ]));

        $this->assertSame($mine->id, SocialAccount::sole()->client_id);
    }

    public function test_the_state_cannot_be_replayed(): void
    {
        $staff = $this->staff();
        $client = $this->client();
        $this->fakeInstagram();
        $state = $this->beginConnect($staff, $client);

        $this->actingAs($staff)->get(route('instagram.callback', ['code' => self::CODE, 'state' => $state]));

        // Consumed on use, so a replayed callback has nothing to match.
        $this->actingAs($staff)
            ->get(route('instagram.callback', ['code' => self::CODE, 'state' => $state]))
            ->assertRedirect(route('clients.index'));

        $this->assertSame(1, SocialAccount::count());
    }

    public function test_declining_on_instagram_changes_nothing(): void
    {
        $staff = $this->staff();
        $client = $this->client();
        $state = $this->beginConnect($staff, $client);

        $this->actingAs($staff)
            ->get(route('instagram.callback', ['error' => 'access_denied', 'state' => $state]))
            ->assertRedirect(route('clients.show', $client))
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'cancelled'));

        $this->assertSame(0, SocialAccount::count());
    }

    public function test_metas_own_error_is_shown_rather_than_a_generic_one(): void
    {
        $staff = $this->staff();
        $client = $this->client();
        $state = $this->beginConnect($staff, $client);

        Http::fake([
            'api.instagram.com/oauth/access_token' => Http::response([
                'error_type' => 'OAuthException',
                'error_message' => 'Invalid redirect_uri',
            ], 400),
        ]);

        $this->actingAs($staff)
            ->get(route('instagram.callback', ['code' => self::CODE, 'state' => $state]))
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'Invalid redirect_uri'));

        $this->assertSame(0, SocialAccount::count());
    }

    public function test_one_instagram_account_cannot_belong_to_two_clients(): void
    {
        $staff = $this->staff();
        $first = $this->client('DJ Thanga Maligai');
        $second = $this->client('SVA Silks');
        $this->fakeInstagram();

        $state = $this->beginConnect($staff, $first);
        $this->actingAs($staff)->get(route('instagram.callback', ['code' => self::CODE, 'state' => $state]));

        // The same Instagram account, authorised again for a different client.
        $state = $this->beginConnect($staff, $second);
        $this->actingAs($staff)
            ->get(route('instagram.callback', ['code' => self::CODE, 'state' => $state]))
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'already connected'));

        $this->assertSame(1, SocialAccount::count());
        $this->assertSame($first->id, SocialAccount::sole()->client_id);
    }

    public function test_disconnecting_keeps_the_row_and_discards_the_token(): void
    {
        $staff = $this->staff();
        $client = $this->client();
        $this->fakeInstagram();
        $state = $this->beginConnect($staff, $client);
        $this->actingAs($staff)->get(route('instagram.callback', ['code' => self::CODE, 'state' => $state]));

        $this->actingAs($staff)->delete(route('instagram.disconnect', $client))->assertRedirect();

        $account = SocialAccount::sole();

        // The record survives so reconnecting is the same account, and so
        // anything that comes to hang off it is not orphaned.
        $this->assertSame(SocialAccount::STATUS_REVOKED, $account->status);
        $this->assertNull($account->access_token);
        $this->assertSame('djthangamaligai', $account->username);
        $this->assertFalse($account->isConnected());
    }

    public function test_reconnecting_reuses_the_same_row(): void
    {
        $staff = $this->staff();
        $client = $this->client();
        $this->fakeInstagram();

        $state = $this->beginConnect($staff, $client);
        $this->actingAs($staff)->get(route('instagram.callback', ['code' => self::CODE, 'state' => $state]));
        $firstId = SocialAccount::sole()->id;

        $this->actingAs($staff)->delete(route('instagram.disconnect', $client));

        $state = $this->beginConnect($staff, $client);
        $this->actingAs($staff)->get(route('instagram.callback', ['code' => self::CODE, 'state' => $state]));

        $this->assertSame(1, SocialAccount::count());
        $this->assertSame($firstId, SocialAccount::sole()->id);
        $this->assertTrue(SocialAccount::sole()->isConnected());
    }

    public function test_connecting_needs_manage_not_merely_view(): void
    {
        $client = $this->client();

        $this->actingAs($this->staff(['view']))
            ->post(route('instagram.connect', $client))
            ->assertForbidden();

        $this->actingAs($this->staff(['view']))
            ->delete(route('instagram.disconnect', $client))
            ->assertForbidden();
    }

    public function test_a_guest_reaches_none_of_it(): void
    {
        $client = $this->client();

        $this->post(route('instagram.connect', $client))->assertRedirect(route('login'));
        $this->get(route('instagram.callback', ['code' => 'x', 'state' => 'y']))->assertRedirect(route('login'));
        $this->get(route('instagram-settings.edit'))->assertRedirect(route('login'));
    }

    public function test_connecting_is_refused_until_the_app_is_configured(): void
    {
        InstagramSetting::current()->forceFill(['app_id' => null, 'app_secret' => null])->save();

        $this->actingAs($this->staff())
            ->post(route('instagram.connect', $this->client()))
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'not set up'));
    }

    public function test_the_settings_screen_never_shows_the_secret_back(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->get(route('instagram-settings.edit'))
            ->assertOk()
            ->assertSee('1122334455')
            ->assertDontSee('instagram-app-secret');
    }

    public function test_saving_the_settings_blank_keeps_the_existing_secret(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->put(route('instagram-settings.update'), ['app_id' => '9988776655', 'app_secret' => ''])
            ->assertRedirect(route('instagram-settings.edit'));

        $settings = InstagramSetting::current();

        $this->assertSame('9988776655', $settings->app_id);
        $this->assertSame('instagram-app-secret', $settings->app_secret);
    }

    public function test_an_employee_cannot_reach_the_settings_screen(): void
    {
        $this->actingAs($this->staff())
            ->get(route('instagram-settings.edit'))
            ->assertForbidden();
    }
}
