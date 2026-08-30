<?php

namespace App\Services\WhatsappFlow\Nodes;

use App\Models\WhatsappFlowSession;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Calls out to a webhook a flow's own graph names -- strictly outbound HTTP,
 * nothing else.
 *
 * This is the deliberate, safe replacement for the reference automation
 * spec's raw-SQL node: a flow's JSON config can never reach the database
 * through this class, or through any other node in this package. Do not add
 * a database/query escape hatch here, no matter how the config is shaped.
 *
 * Config: `url`, optional `payload` (posted as-is), `next`. The response is
 * discarded -- a flow that needs the reply to branch on is a future node
 * type, not this one.
 */
class MakeRequestNode implements NodeHandler
{
    public function handle(WhatsappFlowSession $session, array $nodeConfig): NodeResult
    {
        $url = (string) ($nodeConfig['url'] ?? '');

        if (! self::isAllowedUrl($url)) {
            // Thrown, not silently skipped: FlowEngine's own try/catch turns
            // this into a failed session with the reason recorded, which is
            // the outcome a flow author needs to see and fix in their graph
            // -- not a request that quietly never went anywhere.
            throw new RuntimeException("MakeRequestNode refused an unsafe or invalid URL: {$url}");
        }

        Http::timeout(10)->post($url, $nodeConfig['payload'] ?? []);

        return NodeResult::advance($nodeConfig['next'] ?? null);
    }

    /**
     * A pragmatic SSRF guard: refuses anything that is not plain http(s),
     * and any literal loopback/private/link-local/reserved IP or an
     * obvious localhost-shaped name -- closing the cheap, common case of a
     * flow graph aimed straight at a cloud metadata endpoint
     * (169.254.169.254) or an internal address.
     *
     * What this does not do is resolve a hostname to catch DNS-rebinding
     * SSRF -- a public-looking name that resolves to a private address at
     * request time. Catching that safely means pinning the outbound
     * request to the address that was validated, which needs a custom cURL
     * handle; Laravel's HTTP client does not expose that hook, and a
     * separate gethostbyname() lookup here would just add a second,
     * independent DNS query that the request itself doesn't use (and would
     * make this class perform real DNS lookups even under Http::fake() in
     * tests). Left as a named gap for a follow-up at the network layer
     * rather than approximated here.
     */
    public static function isAllowedUrl(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return false;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return true;
    }
}
