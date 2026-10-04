<?php

namespace App\Http\Controllers;

use App\Models\McpOauthClient;
use App\Models\McpToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * OAuth for the MCP server, so apps that cannot take a pasted token --
 * claude.ai's "Add custom connector", Claude Desktop's connectors, ChatGPT --
 * can still connect.
 *
 * The smallest honest OAuth 2.1 the MCP authorization spec asks for:
 * discovery documents (RFC 9728 + RFC 8414), dynamic client registration
 * (RFC 7591), the authorization-code grant with PKCE S256 only, and public
 * clients only. The access token handed out IS an ordinary McpToken, named
 * after the app, so it shows on the Developer page's Tokens tab and is
 * revoked from there like any other. No refresh tokens: an McpToken does not
 * expire, it is revoked.
 *
 * What protects the portal is the consent step: a code is only ever minted
 * for a signed-in staff member who pressed Allow, and only to a redirect URI
 * the app registered.
 */
class McpOAuthController extends Controller
{
    private const CODE_TTL_SECONDS = 600;

    /** RFC 9728: where the MCP resource says its authorization server is. */
    public function protectedResource(): JsonResponse
    {
        return response()->json([
            'resource' => route('mcp'),
            'authorization_servers' => [url('/')],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => ['mcp'],
            'resource_name' => 'Chakra Portal',
            'resource_documentation' => route('developer.index'),
        ]);
    }

    /** RFC 8414: the authorization server's own description. */
    public function authorizationServer(): JsonResponse
    {
        return response()->json([
            'issuer' => url('/'),
            'authorization_endpoint' => route('mcp.oauth.authorize'),
            'token_endpoint' => route('mcp.oauth.token'),
            'registration_endpoint' => route('mcp.oauth.register'),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => ['mcp'],
        ]);
    }

    /** RFC 7591 dynamic client registration. */
    public function register(Request $request): JsonResponse
    {
        $uris = $request->input('redirect_uris');

        if (! is_array($uris) || $uris === [] || count($uris) > 10) {
            return $this->oauthError('invalid_redirect_uri', 'redirect_uris must be a list of 1-10 URLs.', 400);
        }

        foreach ($uris as $uri) {
            if (! is_string($uri) || ! self::isAcceptableRedirect($uri)) {
                return $this->oauthError('invalid_redirect_uri', 'Redirect URIs must be https, or http on localhost.', 400);
            }
        }

        $name = Str::limit(trim(strip_tags((string) $request->input('client_name', ''))), 100, '') ?: 'MCP app';

        $client = McpOauthClient::create([
            'client_id' => 'mcp_'.Str::random(32),
            'name' => $name,
            'redirect_uris' => array_values($uris),
        ]);

        return response()->json([
            'client_id' => $client->client_id,
            'client_id_issued_at' => $client->created_at->timestamp,
            'client_name' => $client->name,
            'redirect_uris' => $client->redirect_uris,
            'grant_types' => ['authorization_code'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ], 201);
    }

    /** The consent screen. Behind `auth`, so a signed-out person signs in first and lands back here. */
    public function authorize(Request $request): View|RedirectResponse
    {
        [$client, $problem] = $this->checkAuthorizeRequest($request);

        if ($problem) {
            return view('mcp.authorize-error', ['problem' => $problem]);
        }

        if (! $this->mayConnect($request->user())) {
            return view('mcp.authorize-error', ['problem' => 'Only studio staff can connect an AI app to the portal.']);
        }

        return view('mcp.authorize', [
            'client' => $client,
            'user' => $request->user(),
            'params' => $request->only(['client_id', 'redirect_uri', 'state', 'code_challenge', 'code_challenge_method', 'scope', 'resource']),
            'redirectHost' => parse_url($request->query('redirect_uri'), PHP_URL_HOST),
        ]);
    }

    /** Allow or Deny, posted from the consent screen. */
    public function decide(Request $request): View|RedirectResponse
    {
        [$client, $problem] = $this->checkAuthorizeRequest($request);

        if ($problem) {
            return view('mcp.authorize-error', ['problem' => $problem]);
        }

        if (! $this->mayConnect($request->user())) {
            return view('mcp.authorize-error', ['problem' => 'Only studio staff can connect an AI app to the portal.']);
        }

        $redirect = (string) $request->input('redirect_uri');
        $state = $request->input('state');

        if ($request->input('decision') !== 'allow') {
            return redirect()->away($this->withQuery($redirect, array_filter([
                'error' => 'access_denied',
                'state' => $state,
            ], fn ($v) => $v !== null)));
        }

        $code = Str::random(64);

        Cache::put('mcp_oauth_code:'.hash('sha256', $code), [
            'client_id' => $client->client_id,
            'user_id' => $request->user()->id,
            'redirect_uri' => $redirect,
            'code_challenge' => (string) $request->input('code_challenge'),
        ], self::CODE_TTL_SECONDS);

        return redirect()->away($this->withQuery($redirect, array_filter([
            'code' => $code,
            'state' => $state,
        ], fn ($v) => $v !== null)));
    }

    /** Code + PKCE verifier in, McpToken out. */
    public function token(Request $request): JsonResponse
    {
        if ($request->input('grant_type') !== 'authorization_code') {
            return $this->oauthError('unsupported_grant_type', 'Only authorization_code is supported.', 400);
        }

        $code = (string) $request->input('code');
        $verifier = (string) $request->input('code_verifier');

        // pull, not get: a code works once, even if the exchange then fails.
        $grant = $code === '' ? null : Cache::pull('mcp_oauth_code:'.hash('sha256', $code));

        if (! is_array($grant)) {
            return $this->oauthError('invalid_grant', 'The code is wrong, used or expired. Connect again.', 400);
        }

        if ($request->input('client_id') !== null && $request->input('client_id') !== $grant['client_id']) {
            return $this->oauthError('invalid_grant', 'The code was issued to a different app.', 400);
        }

        if ($request->input('redirect_uri') !== null && $request->input('redirect_uri') !== $grant['redirect_uri']) {
            return $this->oauthError('invalid_grant', 'redirect_uri does not match the authorization request.', 400);
        }

        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        if ($verifier === '' || ! hash_equals($grant['code_challenge'], $expected)) {
            return $this->oauthError('invalid_grant', 'PKCE verification failed.', 400);
        }

        $user = User::find($grant['user_id']);
        $client = McpOauthClient::where('client_id', $grant['client_id'])->first();

        if (! $user || ! $client || ! $this->mayConnect($user)) {
            return $this->oauthError('invalid_grant', 'That account can no longer connect.', 400);
        }

        $issued = McpToken::issue($user, Str::limit($client->name.' (connector)', 80, ''));

        return response()->json([
            'access_token' => $issued['plain'],
            'token_type' => 'Bearer',
            'scope' => 'mcp',
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * The checks shared by showing and answering the consent screen. Any
     * failure here is shown on the portal, never redirected: a bad client_id
     * or redirect_uri is exactly when sending the browser elsewhere is unsafe.
     *
     * @return array{0: McpOauthClient|null, 1: string|null}
     */
    private function checkAuthorizeRequest(Request $request): array
    {
        $client = McpOauthClient::where('client_id', (string) $request->input('client_id'))->first();

        if (! $client) {
            return [null, 'This app is not registered with the portal. Remove the connector and add it again.'];
        }

        if (! $client->allowsRedirect((string) $request->input('redirect_uri'))) {
            return [null, 'The app asked to return somewhere it did not register. Not connecting.'];
        }

        if ($request->input('response_type') !== 'code') {
            return [null, 'Unsupported response_type; only "code" is allowed.'];
        }

        if (blank($request->input('code_challenge')) || $request->input('code_challenge_method') !== 'S256') {
            return [null, 'The app did not use PKCE (S256), which the portal requires.'];
        }

        return [$client, null];
    }

    private function mayConnect(?User $user): bool
    {
        return $user !== null && in_array($user->role, [User::ROLE_ADMIN, User::ROLE_EMPLOYEE], true);
    }

    public static function isAcceptableRedirect(string $uri): bool
    {
        $parts = parse_url($uri);

        if (! $parts || empty($parts['scheme']) || empty($parts['host']) || isset($parts['fragment'])) {
            return false;
        }

        if ($parts['scheme'] === 'https') {
            return true;
        }

        return $parts['scheme'] === 'http' && in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true);
    }

    /** @param  array<string, string>  $query */
    private function withQuery(string $uri, array $query): string
    {
        return $uri.(str_contains($uri, '?') ? '&' : '?').http_build_query($query);
    }

    private function oauthError(string $error, string $description, int $status): JsonResponse
    {
        return response()->json(['error' => $error, 'error_description' => $description], $status)
            ->header('Cache-Control', 'no-store');
    }
}
