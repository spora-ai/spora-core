<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MediaArchive;

use Psr\Log\NullLogger;
use Spora\Services\MediaArchive\RemoteMediaFetchException;
use Spora\Services\MediaArchive\RemoteMediaFetcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Direct unit coverage for {@see RemoteMediaFetcher}. Mocks the underlying
 * Symfony HttpClient to drive HEAD/GET status codes and Content-Length
 * values without any real network I/O. Covers the error normalisation
 * paths (non-2xx, oversize Content-Length, transport error) so the
 * service-level fallback to `storage_mode=external` is well-grounded.
 */
function makeFetcher(MockHttpClient $client, int $maxBytes = 100 * 1024 * 1024): RemoteMediaFetcher
{
    return new RemoteMediaFetcher($client, new NullLogger(), 30, $maxBytes);
}

describe('RemoteMediaFetcher::fetch', function (): void {
    it('returns the body + headers on a 2xx response', function (): void {
        $body = 'PNG-DATA';
        $client = new MockHttpClient([
            new MockResponse($body, [
                'http_code' => 200,
                'response_headers' => [
                    'content-type'   => 'image/png',
                    'content-length' => (string) strlen($body),
                ],
            ]),
        ]);

        $result = makeFetcher($client)->fetch('https://cdn.example/x.png');

        expect($result['bytes'])->toBe($body);
        expect($result['contentType'])->toBe('image/png');
        expect($result['contentLength'])->toBe(strlen($body));
    });

    it('throws RemoteMediaFetchException on a non-2xx status', function (): void {
        $client = new MockHttpClient([
            new MockResponse('Not Found', ['http_code' => 404]),
        ]);

        expect(static fn() => makeFetcher($client)->fetch('https://cdn.example/missing.png'))
            ->toThrow(RemoteMediaFetchException::class);
    });

    it('throws RemoteMediaFetchException on a 5xx status', function (): void {
        $client = new MockHttpClient([
            new MockResponse('boom', ['http_code' => 500]),
        ]);

        expect(static fn() => makeFetcher($client)->fetch('https://cdn.example/broken.png'))
            ->toThrow(RemoteMediaFetchException::class, 'HTTP 500');
    });

    it('throws RemoteMediaFetchException when Content-Length exceeds the configured max', function (): void {
    // Symfony's MockResponse validates body size against Content-Length
    // before yielding — we have to provide a matching body. The fetcher's
    // oversize guard fires on the header alone, but here the body must
    // match the declared size so MockResponse stays happy long enough for
    // the fetcher to evaluate it.
    $declaredSize = 64 * 1024; // 64 KiB
    $client = new MockHttpClient([
        new MockResponse(str_repeat('x', $declaredSize), [
            'http_code' => 200,
            'response_headers' => ['content-length' => (string) $declaredSize],
        ]),
    ]);

    expect(static fn() => makeFetcher($client, maxBytes: 1024)->fetch('https://cdn.example/big.png'))
        ->toThrow(RemoteMediaFetchException::class, 'max_promote_bytes');
});

    it('truncates Content-Type at the `;` boundary (charset stripped)', function (): void {
        $client = new MockHttpClient([
            new MockResponse('data', [
                'http_code' => 200,
                'response_headers' => [
                    'content-type'   => 'text/html; charset=utf-8',
                    'content-length' => '4',
                ],
            ]),
        ]);

        $result = makeFetcher($client)->fetch('https://cdn.example/page.html');
        expect($result['contentType'])->toBe('text/html');
    });

    it('returns contentLength=null when the header is missing', function (): void {
        $client = new MockHttpClient([
            new MockResponse('data', [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'image/png'],
                // No content-length header.
            ]),
        ]);

        $result = makeFetcher($client)->fetch('https://cdn.example/x.png');
        expect($result['contentLength'])->toBeNull();
        expect($result['contentType'])->toBe('image/png');
    });
});

describe('RemoteMediaFetcher::probe', function (): void {
    it('returns parsed headers + http status on a successful HEAD', function (): void {
        $client = new MockHttpClient([
            new MockResponse('', [
                'http_code' => 200,
                'response_headers' => [
                    'content-type'   => 'image/png',
                    'content-length' => '12345',
                ],
            ]),
        ]);

        $result = makeFetcher($client)->probe('https://cdn.example/x.png');

        expect($result['httpStatus'])->toBe(200);
        expect($result['contentType'])->toBe('image/png');
        expect($result['contentLength'])->toBe(12345);
    });

    it('returns httpStatus=0 + null fields when the transport throws', function (): void {
        // MockHttpClient throws when the response factory throws — wrap a
        // callable that returns a MockResponse that signals an error.
        $client = new MockHttpClient([
            new MockResponse('', ['error' => 'Connection refused']),
        ]);

        $result = makeFetcher($client)->probe('https://cdn.example/unreachable');
        expect($result['httpStatus'])->toBe(0);
        expect($result['contentType'])->toBeNull();
        expect($result['contentLength'])->toBeNull();
    });

    it('returns contentLength=null when the header is absent on HEAD', function (): void {
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 200]),
        ]);

        $result = makeFetcher($client)->probe('https://cdn.example/x.png');
        expect($result['httpStatus'])->toBe(200);
        expect($result['contentLength'])->toBeNull();
    });

    it('truncates Content-Type at the `;` boundary on HEAD too', function (): void {
        $client = new MockHttpClient([
            new MockResponse('', [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'image/jpeg; charset=binary'],
            ]),
        ]);

        $result = makeFetcher($client)->probe('https://cdn.example/x.jpg');
        expect($result['contentType'])->toBe('image/jpeg');
    });
});

describe('RemoteMediaFetcher error envelope', function (): void {
    it('non-2xx exception carries the URL and status code', function (): void {
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 403]),
        ]);

        $caught = null;
        try {
            makeFetcher($client)->fetch('https://cdn.example/forbidden.png');
        } catch (RemoteMediaFetchException $e) {
            $caught = $e;
        }
        expect($caught)->not->toBeNull();
        expect($caught->url)->toBe('https://cdn.example/forbidden.png');
        expect($caught->httpStatus)->toBe(403);
    });

    it('oversize exception carries 413 + the URL', function (): void {
        $declaredSize = 64 * 1024;
        $client = new MockHttpClient([
            new MockResponse(str_repeat('x', $declaredSize), [
                'http_code' => 200,
                'response_headers' => ['content-length' => (string) $declaredSize],
            ]),
        ]);

        $caught = null;
        try {
            makeFetcher($client, maxBytes: 1024)->fetch('https://cdn.example/big.png');
        } catch (RemoteMediaFetchException $e) {
            $caught = $e;
        }
        expect($caught)->not->toBeNull();
        expect($caught->url)->toBe('https://cdn.example/big.png');
        expect($caught->httpStatus)->toBe(413);
    });
});