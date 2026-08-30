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

        // Redirects are refused outright rather than followed: this node
        // is a fire-and-forget notification, and the pre-flight check
        // above only ever validated the URL the graph named -- not
        // wherever a 3xx response might point next. Following it would
        // let a validated, allowed URL hand the actual request off to an
        // address isAllowedUrl() never saw (a well-known way to smuggle a
        // request past exactly this kind of check). A legitimate webhook
        // endpoint that needs to move should be repointed in the graph,
        // not relied upon to redirect a POST correctly.
        Http::timeout(10)->withoutRedirecting()->post($url, $nodeConfig['payload'] ?? []);

        return NodeResult::advance($nodeConfig['next'] ?? null);
    }

    /**
     * A pragmatic SSRF guard: refuses anything that is not plain http(s),
     * any literal loopback/private/link-local/reserved IPv4 or IPv6
     * address (including the bracketed form a URL's authority carries an
     * IPv6 literal in, and the decimal/octal/shorthand spellings of an
     * IPv4 address that curl/getaddrinfo accept but PHP's own IP filter
     * does not recognise as one), and an obvious localhost-shaped name --
     * closing the cheap, common case of a flow graph aimed straight at a
     * cloud metadata endpoint (169.254.169.254) or an internal address.
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

        // parse_url() leaves an IPv6 literal's brackets on ("[::1]") --
        // filter_var only recognises the bare form, so a bracketed
        // loopback/private address would otherwise fall straight through
        // to "not an IP, must be a hostname" below and be allowed.
        $host = trim($host, '[]');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        // Not a clean IP literal by filter_var's (strict, dotted-quad or
        // standard IPv6) reading -- but that is not the same thing as "must
        // be a real hostname". curl/getaddrinfo happily resolve an IPv4
        // address written as a bare decimal integer (2130706433 ==
        // 127.0.0.1), hex (0x7f000001), octal octets (0177.0.0.1), or a
        // shorthand form (127.1 == 127.0.0.1) -- none of which filter_var
        // recognises as an IP at all. Rather than reimplementing that
        // parsing to normalise and re-check every such form, anything
        // shaped like one of them is refused outright: a real DNS name is
        // never composed of only digits and dots, or only hex digits after
        // a 0x prefix.
        if (preg_match('/^[0-9.]+$/', $host) === 1 || preg_match('/^0x[0-9a-f]+$/i', $host) === 1) {
            return false;
        }

        return true;
    }
}
