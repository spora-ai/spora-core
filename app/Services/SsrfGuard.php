<?php

declare(strict_types=1);

namespace Spora\Services;

/**
 * Blocks outbound fetches to URLs that resolve inside the deployment's
 * own network.
 *
 * Extracted from `ReadUrlTool`, where the checks were private constants
 * applied only on the `fetch_pdf` path — the plain `fetch` path had no
 * private-IP guard at all. Anything that POSTs to a caller-supplied URL
 * (the Agent API's `callback_url`) needs the same gate.
 *
 * The resolve-then-check sequence has a DNS-rebinding window: the host is
 * resolved here and resolved again inside the HTTP client, and the two
 * answers can differ. Closing that would mean pinning the resolved IP into
 * the request, which `symfony/http-client` does not expose for the TLS
 * SNI/hostname pair these callers need. The guard raises the cost of an
 * attack rather than making it impossible.
 */
final class SsrfGuard
{
    private const ALLOWED_SCHEMES = ['https'];

    /**
     * RFC1918, loopback, link-local and carrier-grade NAT ranges, as
     * leading prefixes of a resolved IPv4 address.
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
        if ($url === '' || strlen($url) > 2048) {
            return 'Callback URL is empty or exceeds 2048 characters.';
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return 'Callback URL is not a valid absolute URL.';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            return 'Callback URL must use https.';
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return 'Callback URL has no host.';
        }

        if (in_array($host, self::DENY_HOSTS, true)) {
            return 'Callback URL resolves to a loopback or link-local address.';
        }

        return $this->rejectReasonForHost($host);
    }

    public function isAcceptable(string $url): bool
    {
        return $this->rejectReason($url) === null;
    }

    private function rejectReasonForHost(string $host): ?string
    {
        $bare = trim($host, '[]');

        if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
            return $this->matchesDenyPrefix($bare)
                ? 'Callback URL resolves to a private or reserved address.'
                : null;
        }

        // An unresolvable host is left to the HTTP client, which will fail
        // with a DNS error — there is nothing to protect against if the
        // name never resolves.
        $resolved = @gethostbynamel($host);

        if (!is_array($resolved)) {
            return null;
        }

        foreach ($resolved as $candidate) {
            if ($this->matchesDenyPrefix((string) $candidate)) {
                return 'Callback URL resolves to a private or reserved address.';
            }
        }

        return null;
    }

    private function matchesDenyPrefix(string $ip): bool
    {
        if (str_contains($ip, ':')) {
            // IPv6: only loopback and unspecified are refused. Unique-local
            // (fc00::/7) has no text prefix that can be matched safely here.
            return $ip === '::1' || $ip === '::';
        }

        foreach (self::DENY_IPV4_PREFIXES as $prefix) {
            if (str_starts_with($ip, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
