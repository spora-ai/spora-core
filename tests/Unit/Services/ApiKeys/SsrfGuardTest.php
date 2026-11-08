<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ApiKeys;

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

    it('refuses the whole reserved IPv4 range, not only its first octet', function (string $url): void {
        expect((new SsrfGuard())->rejectReason($url))->not->toBeNull();
    })->with([
        'carrier nat lower bound' => 'https://100.64.0.0/spora',
        'carrier nat inside' => 'https://100.65.0.1/spora',
        'carrier nat upper bound' => 'https://100.127.255.255/spora',
        'benchmarking lower bound' => 'https://198.18.0.0/spora',
        'benchmarking inside' => 'https://198.19.0.1/spora',
        'benchmarking upper bound' => 'https://198.19.255.255/spora',
        'multicast' => 'https://225.0.0.1/spora',
        'reserved' => 'https://250.0.0.1/spora',
        'broadcast' => 'https://255.255.255.255/spora',
    ]);

    it('accepts the addresses just outside the reserved IPv4 ranges', function (string $url): void {
        expect((new SsrfGuard())->rejectReason($url))->toBeNull();
    })->with([
        'below carrier nat' => 'https://100.63.255.255/spora',
        'above carrier nat' => 'https://100.128.0.0/spora',
        'below benchmarking' => 'https://198.17.255.255/spora',
        'above benchmarking' => 'https://198.20.0.0/spora',
        'below multicast' => 'https://223.255.255.255/spora',
    ]);

    it('accepts a public host', function (): void {
        $guard = new SsrfGuard();

        $screen = $guard->screen('https://example.com/spora');
        if ($screen['resolve'] === []) {
            $this->markTestSkipped('This environment resolves no public name.');
        }

        expect($screen['reason'])->toBeNull();

        $withQuery = $guard->screen('https://example.com/spora?a=1&b=2');
        expect($withQuery['reason'])->toBeNull();
    });

    it('accepts a public IP literal', function (): void {
        expect((new SsrfGuard())->rejectReason('https://93.184.216.34/spora'))->toBeNull();
    });

    it('refuses a name that resolves to no address', function (): void {
        // An empty resolve map pins nothing, so the client would run its
        // own lookup — and the second answer can name a private address.
        // A name with no vetted address is refused.
        expect((new SsrfGuard())->rejectReason('https://this-host-does-not-exist.invalid/spora'))
            ->not->toBeNull();
    });

    it('pins a name to the address it vetted, and a literal to nothing', function (): void {
        $guard = new SsrfGuard();

        $screen = $guard->screen('https://example.com/spora');

        if ($screen['resolve'] === []) {
            $this->markTestSkipped('This environment resolves no public name.');
        }

        expect($screen['reason'])->toBeNull()
            ->and($screen['resolve'])->toHaveKey('example.com')
            ->and(filter_var($screen['resolve']['example.com'], FILTER_VALIDATE_IP))->not->toBeFalse();

        // A literal is the destination: there is nothing to resolve and
        // nothing to pin.
        $literal = $guard->screen('https://93.184.216.34/spora');

        expect($literal['reason'])->toBeNull()
            ->and($literal['resolve'])->toBe([]);
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
