<?php

namespace Tests\Feature;

use App\Models\McpOauthClient;
use App\Models\McpToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The OAuth flow claude.ai's "Add custom connector" walks: discovery,
 * registration, consent, token, then an MCP call with that token.
 */
class McpOAuthTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';

    private string $verifier = 'a-very-long-random-pkce-verifier-string-0123456789-abcdefghijklmnop';

    private function challenge(): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '=');
    }

    private function registerClient(): string
    {
        return $this->postJson(route('mcp.oauth.register'), [
            'client_name' => 'Claude',
            'redirect_uris' => [self::REDIRECT],
        ])->assertCreated()->json('client_id');
    }

    private function authorizeParams(string $clientId): array
    {
        return [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'state' => 'xyz',
            'code_challenge' => $this->challenge(),
            'code_challenge_method' => 'S256',
        ];
    }

    private function codeFrom(string $location): string
    {
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('xyz', $query['state']);

        return $query['code'];
    }

    public function test_an_unauthenticated_mcp_call_points_at_the_discovery_document(): void
    {
        $this->postJson(route('mcp'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer realm="Chakra Portal", resource_metadata="'.route('mcp.oauth.protected-resource').'"');
    }

    public function test_discovery_documents_describe_the_flow(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource')->assertOk()
            ->assertJsonPath('resource', route('mcp'))
            ->assertJsonPath('authorization_servers.0', url('/'));

        $this->getJson('/.well-known/oauth-authorization-server')->assertOk()
            ->assertJsonPath('authorization_endpoint', route('mcp.oauth.authorize'))
            ->assertJsonPath('token_endpoint', route('mcp.oauth.token'))
            ->assertJsonPath('registration_endpoint', route('mcp.oauth.register'))
            ->assertJsonPath('code_challenge_methods_supported', ['S256']);
    }

    public function test_the_whole_flow_ends_in_a_working_token(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $clientId = $this->registerClient();

        $this->actingAs($user)->get(route('mcp.oauth.authorize', $this->authorizeParams($clientId)))
            ->assertOk()->assertSee('Connect Claude?');

        $location = $this->actingAs($user)
            ->post(route('mcp.oauth.decide'), $this->authorizeParams($clientId) + ['decision' => 'allow'])
            ->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith(self::REDIRECT.'?', $location);
        $code = $this->codeFrom($location);

        auth()->logout();

        $token = $this->post(route('mcp.oauth.token'), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::REDIRECT,
            'client_id' => $clientId,
            'code_verifier' => $this->verifier,
        ])->assertOk()->assertJsonPath('token_type', 'Bearer')->json('access_token');

        $this->assertSame('Claude (connector)', McpToken::where('user_id', $user->id)->first()->name);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson(route('mcp'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'whoami', 'arguments' => []]])
            ->assertOk()->assertJsonMissingPath('result.isError');

        // A code works once.
        $this->post(route('mcp.oauth.token'), [
            'grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $this->verifier,
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    public function test_a_wrong_pkce_verifier_gets_no_token(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $clientId = $this->registerClient();

        $location = $this->actingAs($user)
            ->post(route('mcp.oauth.decide'), $this->authorizeParams($clientId) + ['decision' => 'allow'])
            ->headers->get('Location');

        $this->post(route('mcp.oauth.token'), [
            'grant_type' => 'authorization_code', 'code' => $this->codeFrom($location), 'code_verifier' => 'wrong',
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

        $this->assertSame(0, McpToken::count());
    }

    public function test_deny_sends_access_denied_and_issues_nothing(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $clientId = $this->registerClient();

        $location = $this->actingAs($user)
            ->post(route('mcp.oauth.decide'), $this->authorizeParams($clientId) + ['decision' => 'deny'])
            ->headers->get('Location');

        $this->assertStringContainsString('error=access_denied', $location);
        $this->assertSame(0, McpToken::count());
    }

    public function test_an_unregistered_redirect_is_never_followed(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $clientId = $this->registerClient();
        $params = ['redirect_uri' => 'https://evil.example/cb'] + $this->authorizeParams($clientId);

        $this->actingAs($user)->post(route('mcp.oauth.decide'), $params + ['decision' => 'allow'])
            ->assertOk()->assertSee('did not register');
    }

    public function test_a_client_login_cannot_connect(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $clientId = $this->registerClient();

        $this->actingAs($client)->get(route('mcp.oauth.authorize', $this->authorizeParams($clientId)))
            ->assertOk()->assertSee('Only studio staff');
    }

    public function test_signed_out_people_are_sent_to_sign_in_first(): void
    {
        $clientId = $this->registerClient();

        $this->get(route('mcp.oauth.authorize', $this->authorizeParams($clientId)))->assertRedirect(route('login'));
    }

    public function test_registration_refuses_a_plain_http_redirect(): void
    {
        $this->postJson(route('mcp.oauth.register'), ['redirect_uris' => ['http://evil.example/cb']])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');

        $this->assertSame(0, McpOauthClient::count());
    }
}
