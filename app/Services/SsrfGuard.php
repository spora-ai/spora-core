<?php

declare(strict_types=1);

namespace Spora\Services;

/**
 * Blocks outbound requests to URLs that resolve inside the deployment's
 * own network.
 *
 * Two callers share it: the Agent API, which POSTs to a caller-supplied
 * `callback_url`, and {@see \Spora\Tools\ReadUrlTool}, which fetches a URL
 * an agent named. They differ in what may be fetched and in how a refusal
 * reads, so the guard takes a policy. The Agent API's policy is the default,
 * so `new SsrfGuard()` behaves as it did before the tool was wired in.
 *
 * The resolve-then-check sequence has a DNS-rebinding window: the host is
 * resolved here and resolved again inside the HTTP client, and the two
 * answers can differ. Closing that would mean pinning the resolved IP into
 * the request, which `symfony/http-client` does not expose for the TLS
 * SNI/hostname pair these callers need. The guard raises the cost of an
 * attack rather than making it impossible — which is why every caller must
 * also refuse to follow redirects, or a redirect is an unscreened host.
 *
 * @phpstan-type Policy array{schemes: list<string>, max_length: int|null, messages: array<string, string>}
 */
final class SsrfGuard
{
    /**
     * The Agent API's policy: `https` only, a length cap matching the
     * `agent_api_callbacks.url` column, and its usual refusal wording.
     *
     * @var Policy
     */
    private const CALLBACK_POLICY = [
        'schemes'    => ['https'],
        'max_length' => 2048,
        'messages'   => [
            'empty_or_too_long' => 'Callback URL is empty or exceeds 2048 characters.',
            'invalid_url'       => 'Callback URL is not a valid absolute URL.',
            'bad_scheme'        => 'Callback URL must use https.',
            'no_host'           => 'Callback URL has no host.',
            'loopback_host'     => 'Callback URL resolves to a loopback or link-local address.',
            'private_address'   => 'Callback URL resolves to a private or reserved address.',
        ],
    ];

    /**
     * A web fetch runs under a looser policy: `http` as well as `https`, no
     * length cap, and `ReadUrlTool`'s usual refusal wording.
     *
     * @var Policy
     */
    public const WEB_FETCH_POLICY = [
        'schemes'    => ['http', 'https'],
        'max_length' => null,
        'messages'   => [
            'empty_or_too_long' => 'A valid absolute URL is required.',
            'invalid_url'       => 'A valid absolute URL is required.',
            'bad_scheme'        => 'Only http:// and https:// URLs are supported.',
            'no_host'           => 'Refusing to fetch a URL with no hostname.',
            'loopback_host'     => 'Refusing to fetch a loopback or link-local hostname.',
            'private_address'   => 'Refusing to fetch a private or loopback IP.',
        ],
    ];

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

    /** @param Policy $policy */
    public function __construct(private readonly array $policy = self::CALLBACK_POLICY) {}

    public static function forWebFetch(): self
    {
        return new self(self::WEB_FETCH_POLICY);
    }

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
        $messages = $this->policy['messages'];

        return match (true) {
            $url === '' || $this->exceedsLengthCap($url)
                => $messages['empty_or_too_long'],
            filter_var($url, FILTER_VALIDATE_URL) === false
                => $messages['invalid_url'],
            !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), $this->policy['schemes'], true)
                => $messages['bad_scheme'],
            default => null,
        };
    }

    private function exceedsLengthCap(string $url): bool
    {
        $max = $this->policy['max_length'];

        return $max !== null && strlen($url) > $max;
    }

    private function rejectReasonForHost(string $host): ?string
    {
        $host = strtolower($host);

        if ($host === '') {
            return $this->policy['messages']['no_host'];
        }

        $literal = $this->rejectReasonForLiteral($host);

        if ($literal !== null) {
            return $literal;
        }

        return $this->rejectReasonForResolved($host);
    }

    /** Everything decidable from the host text itself, without a lookup. */
    private function rejectReasonForLiteral(string $host): ?string
    {
        if (in_array($host, self::DENY_HOSTS, true)) {
            return $this->policy['messages']['loopback_host'];
        }

        $bare = trim($host, '[]');

        if (filter_var($bare, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return $this->matchesDenyPrefix($bare)
            ? $this->policy['messages']['private_address']
            : null;
    }

    private function rejectReasonForResolved(string $host): ?string
    {
        // Both families. `gethostbynamel()` is A-only, so a name whose sole
        // record is `AAAA ::1` returned `false` and was treated as
        // acceptable — the guard's IPv6 branch was unreachable from this
        // path and only ever fired on a literal `[::1]` host.
        foreach ($this->resolveBothFamilies($host) as $candidate) {
            if ($this->matchesDenyPrefix($candidate)) {
                return $this->policy['messages']['private_address'];
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

        return strlen($packed) === 16
            ? $this->matchesDenyPrefixV6($packed)
            : $this->matchesDenyPrefixV4($ip);
    }

    private function matchesDenyPrefixV4(string $ip): bool
    {
        foreach (self::DENY_IPV4_PREFIXES as $prefix) {
            if (str_starts_with($ip, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * IPv6 by prefix bits rather than by text prefix: unique-local
     * (fc00::/7) and link-local (fe80::/10) have no leading text that can be
     * matched safely, so a host written as `0:0:0:0:0:0:0:1` or `fd12::1`
     * would otherwise pass. `/7` covers both 0xFC and 0xFD — testing 0xFC
     * alone misses half the range.
     */
    private function matchesDenyPrefixV6(string $packed): bool
    {
        $first = ord($packed[0]);

        return $first === 0
            || ($first & 0xFE) === 0xFC                                  // fc00::/7
            || ($first === 0xFE && (ord($packed[1]) & 0xC0) === 0x80);    // fe80::/10
    }
}
