<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MediaArchive;

use Psr\Log\NullLogger;
use Spora\Services\MediaArchive\MediaArchiveUrlResolver;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Spora\Services\MediaArchive\MimeSniffer;
use Spora\Services\MediaArchive\RemoteMediaFetcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Unit coverage for {@see MediaArchiveUrlResolver} — the URL branch of
 * the archive's ingest, extracted from
 * {@see \Spora\Services\MediaArchive\MediaArchiveService} so that service
 * stays under Sonar's 20-method-per-class threshold. Covers the
 * resolve() outcomes and the sniffForExternal() precedence chain.
 */
function makeUrlResolver(MockHttpClient $client, bool $promoteExternal = true, int $maxBytes = 100 * 1024 * 1024): MediaArchiveUrlResolver
{
    $fetcher = new RemoteMediaFetcher($client, new NullLogger(), 30, $maxBytes);
    $sniffer = new MimeSniffer();

    return new MediaArchiveUrlResolver($fetcher, $sniffer, new NullLogger(), $promoteExternal, $maxBytes);
}

describe('MediaArchiveUrlResolver::resolve', function (): void {
    it('returns external fallback when promoteExternal is disabled (no fetch)', function (): void {
        $resolver = makeUrlResolver(new MockHttpClient([]), promoteExternal: false);

        [$bytes, $url] = $resolver->resolve('https://93.184.216.34/anything.png');

        expect($bytes)->toBeNull();
        expect($url)->toBe('https://93.184.216.34/anything.png');
    });

    it('returns external fallback when the HEAD probe reports oversize Content-Length', function (): void {
        $client = new MockHttpClient([
            new MockResponse('', [
                'http_code' => 200,
                'response_headers' => ['content-length' => (string) (10 * 1024 * 1024)],
            ]),
        ]);

        $resolver = makeUrlResolver($client, maxBytes: 1024);
        [$bytes, $url] = $resolver->resolve('https://93.184.216.34/big.png');

        expect($bytes)->toBeNull();
        expect($url)->toBe('https://93.184.216.34/big.png');
    });

    it('returns bytes when the body fetch succeeds', function (): void {
        $body = 'PNG-DATA';
        $client = new MockHttpClient([
            // HEAD probe — content-length within cap, so the URL passes
            // the oversize guard and proceeds to the body GET.
            new MockResponse('', [
                'http_code' => 200,
                'response_headers' => ['content-length' => (string) strlen($body)],
            ]),
            // GET — the actual body.
            new MockResponse($body, [
                'http_code' => 200,
                'response_headers' => ['content-length' => (string) strlen($body)],
            ]),
        ]);

        $resolver = makeUrlResolver($client);
        [$bytes, $url] = $resolver->resolve('https://93.184.216.34/x.png');

        expect($bytes)->toBe($body);
        expect($url)->toBe('https://93.184.216.34/x.png');
    });

    it('falls back to external on a non-2xx fetch response', function (): void {
        $client = new MockHttpClient([
            new MockResponse('Not Found', ['http_code' => 404]),
        ]);

        $resolver = makeUrlResolver($client);
        [$bytes, $url] = $resolver->resolve('https://93.184.216.34/missing.png');

        expect($bytes)->toBeNull();
        expect($url)->toBe('https://93.184.216.34/missing.png');
    });

    it('falls back to external when the transport throws', function (): void {
        $client = new MockHttpClient([
            new MockResponse('', ['error' => 'Connection refused']),
        ]);

        $resolver = makeUrlResolver($client);
        [$bytes, $url] = $resolver->resolve('https://93.184.216.34/unreachable.png');

        expect($bytes)->toBeNull();
        expect($url)->toBe('https://93.184.216.34/unreachable.png');
    });
});

describe('MediaArchiveUrlResolver::sniffForExternal', function (): void {
    it('short-circuits on a recognised URL extension', function (): void {
        // `.png` is in the extension table → sniffFromExtension returns
        // 'image/png' without HEAD probing.
        $resolver = makeUrlResolver(new MockHttpClient([]));
        $sniffed = $resolver->sniffForExternal(
            new MediaIngestRequest(url: 'https://93.184.216.34/photo.png'),
            'https://93.184.216.34/photo.png',
        );

        expect($sniffed)->toBe('image/png');
    });

    it('falls back to the HEAD probe Content-Type when the extension is unknown', function (): void {
        // `.bin` isn't in the extension table → probe is consulted.
        $client = new MockHttpClient([
            new MockResponse('', [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'image/jpeg'],
            ]),
        ]);

        $resolver = makeUrlResolver($client);
        $sniffed = $resolver->sniffForExternal(
            new MediaIngestRequest(url: 'https://93.184.216.34/file.bin'),
            'https://93.184.216.34/file.bin',
        );

        expect($sniffed)->toBe('image/jpeg');
    });

    it('falls back to the caller mime when neither the extension nor the probe have an answer', function (): void {
        // No Content-Type on the HEAD probe, no recognised extension.
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 200]),
        ]);

        $resolver = makeUrlResolver($client);
        $sniffed = $resolver->sniffForExternal(
            new MediaIngestRequest(url: 'https://93.184.216.34/mystery.bin', mime: 'image/webp'),
            'https://93.184.216.34/mystery.bin',
        );

        expect($sniffed)->toBe('image/webp');
    });

    it('falls back to application/octet-stream when nothing else matches', function (): void {
        // No Content-Type, no caller hint, no recognised extension.
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 200]),
        ]);

        $resolver = makeUrlResolver($client);
        $sniffed = $resolver->sniffForExternal(
            new MediaIngestRequest(url: 'https://93.184.216.34/mystery.bin'),
            'https://93.184.216.34/mystery.bin',
        );

        expect($sniffed)->toBe('application/octet-stream');
    });
});
