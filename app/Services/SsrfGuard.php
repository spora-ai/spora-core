<?php

declare(strict_types=1);

namespace Spora\Services;

/**
 * Blocks outbound requests to URLs that resolve inside the deployment's
 * own network.
 *
 * Used by the Agent API, which POSTs to a caller-supplied `callback_url`.
 * `ReadUrlTool` has its own, narrower guard: it allows `http` as well as
 * `https`, and its deny list is a private constant there. The two are not
 * unified — merging them would tighten `ReadUrlTool`'s scheme policy, which
 * is a behaviour change to that tool and belongs in its own PR. Until then,
 * a fix to one is not automatically a fix to the other, and the deny lists
 * do not agree.
 *
 * The resolve-then-check sequence has a DNS-rebinding window: the host is
 * resolved here and resolved again inside the HTTP client, and the two
 * answers can differ. Closing that would mean pinning the resolved IP into
 * the request, which `symfony/http-client` does not expose for the TLS
 * SNI/hostname pair these callers need. The guard raises the cost of an
 * attack rather than making it impossible — which is why every caller must
 * also refuse to follow redirects, or a redirect is an unscreened host.
 */
final class SsrfGuard
{
    private const ALLOWED_SCHEMES = ['https'];

    /**
     * Reserved IPv4 space as leading text prefixes: RFC1918, loopback,
     * link-local, carrier-grade NAT, `192.0.0.0/24`, benchmarking
     * (`198.18.0.0/15`), multicast and `240.0.0.0/4`.
     *
     * Not exhaustive — the broadcast address is not covered, and matching on
     * text rather than on packed bits means an address written in an unusual
     * numeric form is caught by `inet_pton()` failing to parse it, not here.
     */
    private const DENY_IPV4_PREFIXES = [
        '0.', '10.', '100.64.', '127.', '169.254.', '172.16.', '172.17.', '172.18.',
        '172.19.', '172.20.', '172.21.', '172.22.', '172.23.', '172.24.', '172.25.',
        '172.26.', '172.27.', '172.28.', '172.29.', '172.30.', '172.31.',
        '192.0.0.', '192.168.', '198.18.', '224.', '240.',
    ];

    private const DENY_HOSTS = ['localhost', '::1', '0.0.0.0', '[::1]'];

    /**
     * @return string|null a human-readable reason the URL is refused, or null when it is acceptable
     */
    public function rejectReason(string $url): ?string
    {
        $shape = $this->rejectReasonForShape($url);

        if ($shape !== null) {
            return $shape;
        }

        return $this->rejectReasonForHost((string) parse_url($url, PHP_URL_HOST));
    }

    /** Everything decidable without a host: length, well-formedness, scheme. */
    private function rejectReasonForShape(string $url): ?string
    {
        if ($url === '' || strlen($url) > 2048) {
            return 'Callback URL is empty or exceeds 2048 characters.';
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return 'Callback URL is not a valid absolute URL.';
        }

        if (!in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), self::ALLOWED_SCHEMES, true)) {
            return 'Callback URL must use https.';
        }

        return null;
    }

    private function rejectReasonForHost(string $host): ?string
    {
        $host = strtolower($host);

        if ($host === '') {
            return 'Callback URL has no host.';
        }

        if (in_array($host, self::DENY_HOSTS, true)) {
            return 'Callback URL resolves to a loopback or link-local address.';
        }

        $bare = trim($host, '[]');

        if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
            return $this->matchesDenyPrefix($bare)
                ? 'Callback URL resolves to a private or reserved address.'
                : null;
        }

        // Both families. `gethostbynamel()` is A-only, so a name whose sole
        // record is `AAAA ::1` returned `false` and was treated as
        // acceptable — the guard's IPv6 branch was unreachable from this
        // path and only ever fired on a literal `[::1]` host.
        foreach ($this->resolveBothFamilies($host) as $candidate) {
            if ($this->matchesDenyPrefix($candidate)) {
                return 'Callback URL resolves to a private or reserved address.';
            }
        }

        // A name that resolves to nothing is left to the HTTP client, which
        // will fail with a DNS error. There is nothing to protect against
        // from a name that never resolves.
        return null;
    }

    /**
     * @return list<string>
     */
    private function resolveBothFamilies(string $host): array
    {
        $addresses = [];

        $v4 = @gethostbynamel($host);

        if (is_array($v4)) {
            foreach ($v4 as $address) {
                $addresses[] = (string) $address;
            }
        }

        $v6 = @dns_get_record($host, DNS_AAAA);

        if (is_array($v6)) {
            foreach ($v6 as $record) {
                if (isset($record['ipv6'])) {
                    $addresses[] = (string) $record['ipv6'];
                }
            }
        }

        return $addresses;
    }

    private function matchesDenyPrefix(string $ip): bool
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 16) {
            // IPv6, by prefix bits rather than by text prefix: unique-local
            // (fc00::/7) and link-local (fe80::/10) have no leading text
            // that can be matched safely, so a host written as `0:0:0:0:0:
            // 0:0:1` or `fd12::1` would otherwise pass. `/7` covers both
            // 0xFC and 0xFD — testing 0xFC alone misses half the range.
            $first = ord($packed[0]);

            return $first === 0
                || ($first & 0xFE) === 0xFC                                  // fc00::/7
                || ($first === 0xFE && (ord($packed[1]) & 0xC0) === 0x80);    // fe80::/10
        }

        foreach (self::DENY_IPV4_PREFIXES as $prefix) {
            if (str_starts_with($ip, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
