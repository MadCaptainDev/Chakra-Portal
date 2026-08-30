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
     * and any host that -- once normalised to a canonical IP -- lands in a
     * loopback/private/link-local/reserved range, or an obvious
     * localhost-shaped name. Closes the cheap, common case of a flow graph
     * aimed straight at a cloud metadata endpoint (169.254.169.254) or an
     * internal address, under any of the address forms curl/getaddrinfo
     * accept for it:
     *
     * - a bracketed IPv6 literal ("[::1]") -- parse_url() leaves the
     *   brackets on, which filter_var() does not strip on its own;
     * - an IPv4-mapped IPv6 literal ("::ffff:169.254.169.254") -- a
     *   perfectly valid IPv6 address by filter_var()'s own reading, and
     *   "public" by its private/reserved-range flags too, because those
     *   flags never look inside an IPv6 literal at the IPv4 address it
     *   carries;
     * - the classic BSD/curl "numbers-and-dots" IPv4 notation, which
     *   accepts far more than a strict four-decimal-octet dotted quad:
     *   a bare 32-bit integer (2130706433 == 127.0.0.1), hex
     *   (0x7f000001), octal octets (0177.0.0.1), shorthand forms
     *   (127.1 == 127.0.0.1), and any mix of the three per octet
     *   (192.168.0x1) -- none of which PHP's own filter_var(), ip2long(),
     *   or inet_pton() recognise as an IP at all (verified directly
     *   against this codebase's PHP build, not assumed).
     *
     * The strategy behind all of the above is the same one: normalise
     * first, then run the private/reserved-range check on the canonical
     * result -- rather than pattern-matching (and inevitably chasing)
     * every string spelling of the same handful of addresses one at a
     * time. normalizeIpv4Literal() below is this class's own
     * canonicaliser for the curl-style numeric forms, since none of PHP's
     * built-ins parse them; embeddedIpv4() extracts the address folded
     * into an IPv4-mapped IPv6 literal so it can be checked directly
     * instead of the wrapping IPv6 form, which the privacy check is blind
     * to.
     *
     * Two things this deliberately does NOT do, both for the same
     * underlying reason -- there is no hook in Laravel's HTTP client (nor
     * in PHP's stream/cURL layer, without a custom handler) to pin the
     * actual outbound connection to whatever address was validated here:
     *
     * - True DNS-rebinding SSRF: a public-looking hostname that resolves
     *   to a private address only at connection time, after this check
     *   has already passed.
     * - A hostname that plainly, statically resolves to a private
     *   address right now: resolving hostnames here (e.g. via
     *   gethostbyname()) was considered and deliberately not done. It
     *   would not close either of the two gaps this round's review
     *   actually found (both are IP-*literal* encodings, fully closed by
     *   the canonicalisation above, with no DNS involved) or the
     *   rebinding gap above it (a resolve-then-check here still has
     *   nothing pinning the later real connection to what it saw) -- and
     *   measured directly against this environment's resolver, an
     *   unresolvable/slow name took upward of two and a half seconds to
     *   fail, which is not a cost worth adding to every flow node's
     *   synchronous execution (FlowEngine's own wall-clock cap exists
     *   precisely to bound this kind of thing) for a check that would
     *   still be incomplete.
     */
    public static function isAllowedUrl(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return false;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        $host = trim($host, '[]');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPublicIp($host);
        }

        $literal = self::normalizeIpv4Literal($host);

        if ($literal !== null) {
            return self::isPublicIp($literal);
        }

        return true;
    }

    /**
     * Runs the actual private/loopback/link-local/reserved check, first
     * substituting the IPv4 address embedded in an IPv4-mapped IPv6
     * literal (if $ip is one) for $ip itself -- see the class docblock for
     * why that substitution is necessary at all.
     */
    private static function isPublicIp(string $ip): bool
    {
        $ip = self::embeddedIpv4($ip) ?? $ip;

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * The IPv4 address folded into an IPv4-mapped IPv6 literal
     * ("::ffff:a.b.c.d"), or null if $ip isn't one. This is the only
     * embedded form curl/getaddrinfo specially resolve to the wrapped
     * IPv4 address -- the older, deprecated "IPv4-compatible" form
     * ("::a.b.c.d") is not given the same treatment by a modern resolver,
     * so it is deliberately not handled here; it is simply validated as
     * the (mostly-zero, non-routable) IPv6 address it literally is.
     */
    private static function embeddedIpv4(string $ip): ?string
    {
        $packed = @inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }

        if (substr($packed, 0, 12) !== str_repeat("\x00", 10)."\xff\xff") {
            return null;
        }

        return inet_ntop(substr($packed, 12, 4)) ?: null;
    }

    /**
     * Canonicalises the classic BSD/curl "numbers-and-dots" IPv4 notation
     * into a plain dotted quad, or returns null if $host isn't shaped like
     * one at all (i.e. it's a real hostname label). PHP has no built-in
     * that parses this grammar -- filter_var(), ip2long(), and
     * inet_pton() were each tried directly against every form below and
     * none recognise them -- so this reimplements the same rules curl's
     * own inet_aton-equivalent parser applies: each dot-separated
     * component may be decimal, octal (a leading 0), or hex (a leading
     * 0x/0X); with fewer than four components, the last one absorbs
     * whatever bits the earlier, always-single-octet components didn't
     * claim.
     *
     * Verified directly against every alternate form found across this
     * SSRF guard's review rounds: 2130706433, 0177.0.0.1, 127.1,
     * 0x7f000001, 0x7f.0x0.0x0.0x1, and 192.168.0x1 all canonicalise to
     * 127.0.0.1/192.168.0.1 as appropriate, while ordinary hostnames
     * (example.test, api.example.com, ...) correctly return null.
     */
    private static function normalizeIpv4Literal(string $host): ?string
    {
        $parts = explode('.', $host);
        $count = count($parts);

        if ($count < 1 || $count > 4) {
            return null;
        }

        $values = [];

        foreach ($parts as $part) {
            if ($part === '') {
                return null;
            }

            if (preg_match('/^0[xX][0-9a-fA-F]+$/', $part) === 1) {
                $values[] = hexdec(substr($part, 2));
            } elseif (preg_match('/^0[0-7]*$/', $part) === 1) {
                $values[] = octdec($part);
            } elseif (preg_match('/^[1-9][0-9]*$/', $part) === 1) {
                $values[] = (int) $part;
            } else {
                // Not a numeric component at all -- this is a real
                // hostname label, not an IPv4 literal in disguise.
                return null;
            }
        }

        // Every component but the last is always a single octet (0-255);
        // the last one absorbs the remaining bits, so its ceiling depends
        // on how many components came before it.
        $maxForLast = match ($count) {
            1 => 4294967295,
            2 => 16777215,
            3 => 65535,
            4 => 255,
        };

        foreach (array_slice($values, 0, -1) as $value) {
            if ($value > 255) {
                return null;
            }
        }

        if (end($values) > $maxForLast) {
            return null;
        }

        $shifts = match ($count) {
            1 => [0],
            2 => [24, 0],
            3 => [24, 16, 0],
            4 => [24, 16, 8, 0],
        };

        $result = 0;

        foreach ($values as $i => $value) {
            $result |= $value << $shifts[$i];
        }

        return sprintf(
            '%d.%d.%d.%d',
            ($result >> 24) & 0xFF,
            ($result >> 16) & 0xFF,
            ($result >> 8) & 0xFF,
            $result & 0xFF,
        );
    }
}
