<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AgentApi;

use Spora\Services\SsrfGuard;

/**
 * The guard on `callback_url`, which is the one place the Agent API lets a
 * caller name a URL the server will POST to.
 */
describe('SsrfGuard', function (): void {
    it('refuses anything that is not an absolute https URL', function (string $url): void {
        expect((new SsrfGuard())->rejectReason($url))->not->toBeNull();
    })->with([
        'plain http' => 'http://hooks.example.com/spora',
        'file scheme' => 'file:///etc/passwd',
        'gopher scheme' => 'gopher://example.com/',
        'no scheme' => 'hooks.example.com/spora',
        'empty' => '',
        'over 2048 chars' => 'https://example.com/' . str_repeat('a', 2048),
    ]);

    it('refuses hosts inside the deployment network', function (string $url): void {
        expect((new SsrfGuard())->rejectReason($url))->not->toBeNull();
    })->with([
        'cloud metadata' => 'https://169.254.169.254/latest/meta-data',
        'loopback' => 'https://127.0.0.1/spora',
        'rfc1918 ten' => 'https://10.0.0.5/spora',
        'rfc1918 192' => 'https://192.168.1.10/spora',
        'rfc1918 172 lower' => 'https://172.16.0.1/spora',
        'rfc1918 172 upper' => 'https://172.31.255.1/spora',
        'carrier nat' => 'https://100.64.0.1/spora',
        'localhost by name' => 'https://localhost/spora',
        'unspecified' => 'https://0.0.0.0/spora',
        'ipv6 loopback' => 'https://[::1]/spora',
    ]);

    it('accepts a public host', function (): void {
        $guard = new SsrfGuard();

        expect($guard->rejectReason('https://hooks.example.com/spora'))->toBeNull();
        expect($guard->rejectReason('https://hooks.example.com/spora?a=1&b=2'))->toBeNull();
    });

    it('accepts a public IP literal', function (): void {
        expect((new SsrfGuard())->rejectReason('https://93.184.216.34/spora'))->toBeNull();
    });

    it('leaves an unresolvable name to the HTTP client', function (): void {
        // Nothing to protect against if the name never resolves, and the
        // transport will report the DNS failure.
        expect((new SsrfGuard())->rejectReason('https://this-host-does-not-exist.invalid/spora'))
            ->toBeNull();
    });

    it('refuses an IPv6 literal outside the reserved ranges', function (string $url): void {
        expect((new SsrfGuard())->rejectReason($url))->not->toBeNull();
    })->with([
        'loopback' => 'https://[::1]/spora',
        'unspecified' => 'https://[::]/spora',
        'unique-local' => 'https://[fc00::1]/spora',
        'unique-local fd' => 'https://[fd12:3456:789a::1]/spora',
        'link-local' => 'https://[fe80::1]/spora',
        // Written in full rather than compressed: a text-prefix match would
        // not see these as loopback or unspecified at all.
        'loopback expanded' => 'https://[0:0:0:0:0:0:0:1]/spora',
        'unique-local expanded' => 'https://[0:0:0:0:0:0:fc00:1]/spora',
    ]);

    it('accepts a public IPv6 literal', function (): void {
        expect((new SsrfGuard())->rejectReason('https://[2606:4700:4700::1111]/spora'))->toBeNull();
    });
});
