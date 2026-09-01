<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\InstagramSetting;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Instagram\InstagramGraph;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A client connecting their own Instagram account, self-service, through the
 * client portal -- the same OAuth dance as
 * tests/Feature/InstagramConnectionTest.php's staff-initiated flow, and the
 * same single shared callback, just with the client id read from the
 * signed-in user's own row instead of a route segment reachable only by
 * clients,manage. That test file already covers the security design
 * (state matching, replay, cross-client tampering) in depth; this one
 * covers what changes when a client starts the flow instead of staff --
 * chiefly, where it redirects to.
 */
class ClientInstagramConnectionTest extends TestCase
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

    private function client(array $overrides = []): Client
    {
        return Client::create(array_merge(['name' => 'Thillai Pets Clinic'], $overrides));
    }

    private function loginFor(Client $client): User
    {
        return User::factory()->create(['role' => User::ROLE_CLIENT, 'client_id' => $client->id]);
    }

    private function fakeInstagram(string $username = 'thillaipets'): void
    {
        Http::fake([
            'api.instagram.com/oauth/access_token' => Http::response([
                'access_token' => 'IGQV-short-lived',
                'user_id' => '17841400000000002',
                'permissions' => ['instagram_business_basic', 'instagram_business_manage_insights'],
            ]),
            'graph.instagram.com/access_token*' => Http::response([
                'access_token' => 'IGQV-long-lived',
                'token_type' => 'bearer',
                'expires_in' => 5184000,
            ]),
            'graph.instagram.com/*/me*' => Http::response([
                'user_id' => '17841400000000002',
                'username' => $username,
                'account_type' => 'BUSINESS',
                'followers_count' => 980,
                'media_count' => 42,
            ]),
        ]);
    }

    private function beginConnect(User $login): string
    {
        $this->actingAs($login)
            ->post(route('client.instagram.connect'))
            ->assertRedirectContains(InstagramGraph::AUTHORIZE_URL);

        return session('instagram.oauth')['state'];
    }

    public function test_a_client_can_start_connecting_their_own_account(): void
    {
        $client = $this->client();
        $login = $this->loginFor($client);

        $response = $this->actingAs($login)->post(route('client.instagram.connect'));

        $this->assertStringStartsWith(InstagramGraph::AUTHORIZE_URL, $response->headers->get('Location'));
        $this->assertSame($client->id, session('instagram.oauth')['client_id']);
    }

    public function test_a_completed_callback_stores_the_connection_and_returns_to_the_social_screen(): void
    {
        $client = $this->client();
        $login = $this->loginFor($client);
        $this->fakeInstagram();

        $state = $this->beginConnect($login);

        $this->actingAs($login)
            ->get(route('instagram.callback', ['code' => self::CODE, 'state' => $state]))
            ->assertRedirect(route('client.social'));

        $account = SocialAccount::sole();
        $this->assertSame($client->id, $account->client_id);
        $this->assertSame('thillaipets', $account->username);
        $this->assertTrue($account->isConnected());
        $this->assertSame($login->id, $account->connected_by_id);
    }

    public function test_a_callback_with_no_pending_attempt_returns_to_the_social_screen_not_the_staff_client_list(): void
    {
        // clients.index is a staff route -- a client-role user hitting it
        // would 403, not see a helpful message.
        $login = $this->loginFor($this->client());

        $this->actingAs($login)
            ->get(route('instagram.callback', ['code' => self::CODE, 'state' => 'invented']))
            ->assertRedirect(route('client.social'));

        $this->assertSame(0, SocialAccount::count());
    }

    public function test_declining_on_instagram_returns_to_the_social_screen(): void
    {
        $login = $this->loginFor($this->client());
        $state = $this->beginConnect($login);

        $this->actingAs($login)
            ->get(route('instagram.callback', ['error' => 'access_denied', 'state' => $state]))
            ->assertRedirect(route('client.social'))
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'cancelled'));
    }

    public function test_a_client_can_disconnect_their_own_account(): void
    {
        $client = $this->client();
        $login = $this->loginFor($client);
        $this->fakeInstagram();
        $state = $this->beginConnect($login);
        $this->actingAs($login)->get(route('instagram.callback', ['code' => self::CODE, 'state' => $state]));

        $this->actingAs($login)->delete(route('client.instagram.disconnect'))->assertRedirect();

        $account = SocialAccount::sole();
        $this->assertSame(SocialAccount::STATUS_REVOKED, $account->status);
        $this->assertFalse($account->isConnected());
    }

    public function test_disconnecting_with_nothing_connected_is_a_no_op(): void
    {
        $login = $this->loginFor($this->client());

        $this->actingAs($login)
            ->delete(route('client.instagram.disconnect'))
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'no Instagram account'));

        $this->assertSame(0, SocialAccount::count());
    }

    public function test_staff_cannot_reach_the_client_self_service_routes(): void
    {
        $staff = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($staff)->get(route('client.social'))->assertForbidden();
        $this->actingAs($staff)->post(route('client.instagram.connect'))->assertForbidden();
        $this->actingAs($staff)->delete(route('client.instagram.disconnect'))->assertForbidden();
    }

    public function test_a_guest_reaches_none_of_it(): void
    {
        $this->get(route('client.social'))->assertRedirect(route('login'));
        $this->post(route('client.instagram.connect'))->assertRedirect(route('login'));
        $this->delete(route('client.instagram.disconnect'))->assertRedirect(route('login'));
    }

    public function test_connecting_is_refused_until_the_app_is_configured(): void
    {
        InstagramSetting::current()->forceFill(['app_id' => null, 'app_secret' => null])->save();

        $this->actingAs($this->loginFor($this->client()))
            ->post(route('client.instagram.connect'))
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'not set up'));
    }

    public function test_the_social_screen_shows_not_connected_by_default(): void
    {
        $login = $this->loginFor($this->client());

        $this->actingAs($login)
            ->get(route('client.social'))
            ->assertOk()
            ->assertSee('Connect Instagram')
            ->assertDontSee('View Analytics')
            ->assertDontSee('Monthly Report');
    }

    public function test_the_social_screen_shows_connected_state_and_no_staff_only_links(): void
    {
        $client = $this->client();
        $login = $this->loginFor($client);
        $this->fakeInstagram('thillaipets');
        $state = $this->beginConnect($login);
        $this->actingAs($login)->get(route('instagram.callback', ['code' => self::CODE, 'state' => $state]));

        $this->actingAs($login)
            ->get(route('client.social'))
            ->assertOk()
            ->assertSee('thillaipets')
            ->assertSee('Disconnect')
            ->assertDontSee('View Analytics')
            ->assertDontSee('Monthly Report');
    }
}
