<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An app that registered for the MCP OAuth flow (RFC 7591 dynamic client
 * registration). Public clients only -- no secret; PKCE is what proves the
 * app that asked for the code is the one swapping it for a token.
 */
class McpOauthClient extends Model
{
    protected $fillable = ['client_id', 'name', 'redirect_uris'];

    protected $casts = ['redirect_uris' => 'array'];

    public function allowsRedirect(string $uri): bool
    {
        return in_array($uri, $this->redirect_uris ?? [], true);
    }
}
