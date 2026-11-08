<?php

declare(strict_types=1);

namespace Spora\Services;

/**
 * Blocks outbound requests to URLs that resolve inside the deployment's
 * own network.
 *
 * Callers share it: the Agent API, which POSTs to a caller-supplied
 * `callback_url` and screens it again before every delivery, and the web
 * fetchers — {@see \Spora\Tools\ReadUrlTool} and
 * {@see \Spora\Services\MediaArchive\RemoteMediaFetcher}. They differ in
 * what may be fetched and in how a refusal reads, so the guard takes a
 * policy. The Agent API's policy is the default, so `new SsrfGuard()`
 * behaves as it did before the tool was wired in.
 *
 * The host is resolved here and the vetted address is handed back with the
 * verdict, so a caller can pin its request to that address —
 * `symfony/http-client`'s `resolve` option connects to the pinned address
 * while the `Host` header, the TLS SNI and the certificate check keep the
 * hostname. A caller that ignores the pin still has the resolve-then-check
 * window: the client resolves the name again, and the two answers can
 * differ. Every caller must also refuse to follow redirects, or a redirect
 * is an unscreened host.
 *
 * @phpstan-type Policy array{schemes: list<string>, max_length: int|null, messages: array<string, string>}
 * @phpstan-type Screen array{reason: string|null, resolve: array<string, string>}
 */
final class SsrfGuard
{
    /**
     * The Agent API's policy: `https` only, a length cap matching the
     * `api_callbacks.url` column, and its usual refusal wording.
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
            'unresolvable_host' => 'Callback URL host could not be resolved to an address.',
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
            'unresolvable_host' => 'Refusing to fetch a hostname that could not be resolved to an address.',
        ],
    ];

    /**
     * Reserved IPv4 space, as network/prefix pairs: RFC1918, loopback,
     * link-local, carrier-grade NAT, `192.0.0.0/24`, benchmarking
     * (`198.18.0.0/15`), multicast and `240.0.0.0/4`.
     *
     * Whole ranges, not text prefixes: `100.65.0.0` is inside the
     * carrier-grade NAT block and `198.19.0.0` is inside the benchmarking
     * block, and a prefix would have let both through.
     *
     * Not exhaustive — the documentation ranges (`192.0.2.0/24`,
     * `198.51.100.0/24`, `203.0.113.0/24`) are not listed.
     *
     * Every address here is a reserved-network constant the guard exists
     * to refuse, so a hardcoded-address finding on one of them says
     * nothing: they are the deny list, not a host the deployment talks
     * to.
     *
     * @var list<array{0: string, 1: int}>
     */
    private const DENY_IPV4_RANGES = [
        ['0.0.0.0', 8],
        ['10.0.0.0', 8],      // NOSONAR php:S1313 — reserved range, denied by design
        ['100.64.0.0', 10],  // NOSONAR php:S1313 — reserved range, denied by design
        ['127.0.0.0', 8],
        ['169.254.0.0', 16], // NOSONAR php:S1313 — reserved range, denied by design
        ['172.16.0.0', 12],  // NOSONAR php:S1313 — reserved range, denied by design
        ['192.0.0.0', 24],   // NOSONAR php:S1313 — reserved range, denied by design
        ['192.168.0.0', 16], // NOSONAR php:S1313 — reserved range, denied by design
        ['198.18.0.0', 15],  // NOSONAR php:S1313 — reserved range, denied by design
        ['224.0.0.0', 4],    // NOSONAR php:S1313 — reserved range, denied by design
        ['240.0.0.0', 4],    // NOSONAR php:S1313 — reserved range, denied by design
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
        return $this->screen($url)['reason'];
    }

    /**
     * Screen a URL and report the address its request may be pinned to.
     *
     * The `resolve` map is the HTTP client's own `resolve` option: the
     * request connects to the vetted address and keeps the hostname for the
     * `Host` header, the TLS SNI and the certificate check.
     *
     * @return Screen
     */
    public function screen(string $url): array
    {
        $shape = $this->rejectReasonForShape($url);

        if ($shape !== null) {
            return ['reason' => $shape, 'resolve' => []];
        }

        return $this->screenHost(strtolower((string) parse_url($url, PHP_URL_HOST)));
    }

    /** @return Screen */
    private function screenHost(string $host): array
    {
        $reason = $this->rejectReasonForHost($host);

        if ($reason !== null) {
            return ['reason' => $reason, 'resolve' => []];
        }

        return $this->screenAddresses($host);
    }

    /** The reasons a host is refused before anything is resolved. */
    private function rejectReasonForHost(string $host): ?string
    {
        $literal = $this->literalAddress($host);

        return match (true) {
            $host === '' => $this->policy['messages']['no_host'],
            in_array($host, self::DENY_HOSTS, true) => $this->policy['messages']['loopback_host'],
            $literal !== null && $this->isDeniedAddress($literal) => $this->policy['messages']['private_address'],
            default => null,
        };
    }

    /** @return Screen */
    private function screenAddresses(string $host): array
    {
        if ($this->literalAddress($host) !== null) {
            // A literal address is the destination: there is nothing to
            // resolve and nothing to pin.
            return ['reason' => null, 'resolve' => []];
        }

        // Both families. `gethostbynamel()` is A-only, so a name whose sole
        // record is `AAAA ::1` returned `false` and was treated as
        // acceptable — the guard's IPv6 branch was unreachable from this
        // path and only ever fired on a literal `[::1]` host.
        $addresses = $this->resolveBothFamilies($host);

        foreach ($addresses as $candidate) {
            if ($this->isDeniedAddress($candidate)) {
                return ['reason' => $this->policy['messages']['private_address'], 'resolve' => []];
            }
        }

        // A name that resolves to nothing is refused. An empty resolve map
        // pins nothing, so the client would run its own lookup — and the
        // second answer can name a private address. Fail closed: only a
        // name with a vetted address goes on.
        return [
            'reason'  => $addresses === [] ? $this->policy['messages']['unresolvable_host'] : null,
            'resolve' => $addresses === [] ? [] : [$host => $addresses[0]],
        ];
    }

    /** The host as an IP literal, or null when it is a name. */
    private function literalAddress(string $host): ?string
    {
        $bare = trim($host, '[]');

        return filter_var($bare, FILTER_VALIDATE_IP) !== false ? $bare : null;
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

    private function isDeniedAddress(string $ip): bool
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        return strlen($packed) === 16
            ? $this->isDeniedAddressV6($packed)
            : $this->isDeniedAddressV4($packed);
    }

    private function isDeniedAddressV4(string $packed): bool
    {
        foreach (self::DENY_IPV4_RANGES as [$network, $bits]) {
            $networkBytes = @inet_pton($network);

            if ($networkBytes !== false && $this->sharesPrefix($packed, $networkBytes, $bits)) {
                return true;
            }
        }

        return false;
    }

    /** Whether the two packed addresses agree on the network's first `$bits` bits. */
    private function sharesPrefix(string $address, string $network, int $bits): bool
    {
        $wholeBytes = intdiv($bits, 8);

        if ($wholeBytes > 0 && strncmp($address, $network, $wholeBytes) !== 0) {
            return false;
        }

        $spareBits = $bits % 8;

        if ($spareBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $spareBits)) & 0xFF;

        return (ord($address[$wholeBytes]) & $mask) === (ord($network[$wholeBytes]) & $mask);
    }

    /**
     * IPv6 by prefix bits rather than by text prefix: unique-local
     * (fc00::/7) and link-local (fe80::/10) have no leading text that can be
     * matched safely, so a host written as `0:0:0:0:0:0:0:1` or `fd12::1`
     * would otherwise pass. `/7` covers both 0xFC and 0xFD — testing 0xFC
     * alone misses half the range.
     */
    private function isDeniedAddressV6(string $packed): bool
    {
        $first = ord($packed[0]);

        return $first === 0
            || ($first & 0xFE) === 0xFC                                  // fc00::/7
            || ($first === 0xFE && (ord($packed[1]) & 0xC0) === 0x80);    // fe80::/10
    }
}
