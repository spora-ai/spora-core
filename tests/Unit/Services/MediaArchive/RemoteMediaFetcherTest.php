<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MediaArchive;

use Psr\Log\NullLogger;
use Spora\Services\MediaArchive\RemoteMediaFetcher;
use Spora\Services\MediaArchive\RemoteMediaFetchException;
use Spora\Services\SsrfGuard;
use Symfony\Component\HttpClient\Chunk\DataChunk;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Direct unit coverage for {@see RemoteMediaFetcher}. Mocks the underlying
 * Symfony HttpClient to drive HEAD/GET status codes and Content-Length
 * values without any real network I/O. Covers the error normalisation
 * paths (non-2xx, oversize Content-Length, transport error) so the
 * service-level fallback to `storage_mode=external` is well-grounded.
 */
function makeFetcher(HttpClientInterface $client, int $maxBytes = 100 * 1024 * 1024): RemoteMediaFetcher
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

        $result = makeFetcher($client)->fetch('https://93.184.216.34/x.png');

        expect($result['bytes'])->toBe($body);
        expect($result['contentType'])->toBe('image/png');
        expect($result['contentLength'])->toBe(strlen($body));
    });

    it('throws RemoteMediaFetchException on a non-2xx status', function (): void {
        $client = new MockHttpClient([
            new MockResponse('Not Found', ['http_code' => 404]),
        ]);

        expect(static fn() => makeFetcher($client)->fetch('https://93.184.216.34/missing.png'))
            ->toThrow(RemoteMediaFetchException::class);
    });

    it('throws RemoteMediaFetchException on a 5xx status', function (): void {
        $client = new MockHttpClient([
            new MockResponse('boom', ['http_code' => 500]),
        ]);

        expect(static fn() => makeFetcher($client)->fetch('https://93.184.216.34/broken.png'))
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

        expect(static fn() => makeFetcher($client, maxBytes: 1024)->fetch('https://93.184.216.34/big.png'))
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

        $result = makeFetcher($client)->fetch('https://93.184.216.34/page.html');
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

        $result = makeFetcher($client)->fetch('https://93.184.216.34/x.png');
        expect($result['contentLength'])->toBeNull();
        expect($result['contentType'])->toBe('image/png');
    });

    it('throws RemoteMediaFetchException (httpStatus=0) on a transport failure', function (): void {
        // `error` in MockResponse simulates a network-level failure —
        // getStatusCode()/getHeaders() should both surface as
        // RemoteMediaFetchException so the service can fall back.
        $client = new MockHttpClient([
            new MockResponse('', ['error' => 'Connection refused']),
        ]);

        $caught = null;
        try {
            makeFetcher($client)->fetch('https://93.184.216.34/unreachable.png');
        } catch (RemoteMediaFetchException $e) {
            $caught = $e;
        }
        expect($caught)->not->toBeNull();
        expect($caught->httpStatus)->toBe(0);
        expect($caught->url)->toBe('https://93.184.216.34/unreachable.png');
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

        $result = makeFetcher($client)->probe('https://93.184.216.34/x.png');

        expect($result['httpStatus'])->toBe(200);
        expect($result['contentType'])->toBe('image/png');
        expect($result['contentLength'])->toBe(12345);
    });

    it('returns httpStatus=0 + null fields when the transport throws', function (): void {
        // A MockResponse carrying an `error` option simulates a
        // network-level failure — the probe catches it and reports no
        // status.
        $client = new MockHttpClient([
            new MockResponse('', ['error' => 'Connection refused']),
        ]);

        $result = makeFetcher($client)->probe('https://93.184.216.34/unreachable');
        expect($result['httpStatus'])->toBe(0);
        expect($result['contentType'])->toBeNull();
        expect($result['contentLength'])->toBeNull();
    });

    it('returns contentLength=null when the header is absent on HEAD', function (): void {
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 200]),
        ]);

        $result = makeFetcher($client)->probe('https://93.184.216.34/x.png');
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

        $result = makeFetcher($client)->probe('https://93.184.216.34/x.jpg');
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
            makeFetcher($client)->fetch('https://93.184.216.34/forbidden.png');
        } catch (RemoteMediaFetchException $e) {
            $caught = $e;
        }
        expect($caught)->not->toBeNull();
        expect($caught->url)->toBe('https://93.184.216.34/forbidden.png');
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
            makeFetcher($client, maxBytes: 1024)->fetch('https://93.184.216.34/big.png');
        } catch (RemoteMediaFetchException $e) {
            $caught = $e;
        }
        expect($caught)->not->toBeNull();
        expect($caught->url)->toBe('https://93.184.216.34/big.png');
        expect($caught->httpStatus)->toBe(413);
    });
});

describe('RemoteMediaFetcher destination policy', function (): void {
    it('refuses a destination inside the deployment network', function (): void {
        $client = new WatchedHttpClient('internal secret');

        expect(static fn() => makeFetcher($client)->fetch('http://127.0.0.1/secret.png'))
            ->toThrow(RemoteMediaFetchException::class);
    });

    it('refuses a loopback hostname', function (): void {
        $client = new WatchedHttpClient('internal secret');

        expect(static fn() => makeFetcher($client)->fetch('http://localhost/secret.png'))
            ->toThrow(RemoteMediaFetchException::class);
    });

    it('refuses a redirect into the deployment network', function (): void {
        // The redirect target is a host the guard screened never saw.
        $client = new WatchedHttpClient('internal secret', redirectTo: 'http://127.0.0.1/secret.png');

        expect(static fn() => makeFetcher($client)->fetch('https://93.184.216.34/redirect.png'))
            ->toThrow(RemoteMediaFetchException::class, 'private or loopback IP');
    });

    it('follows a redirect to a public destination', function (): void {
        $client = new WatchedHttpClient('redirected bytes', redirectTo: 'https://93.184.216.34/final.png');

        $result = makeFetcher($client)->fetch('https://93.184.216.34/redirect.png');

        expect($result['bytes'])->toBe('redirected bytes');
    });
});

describe('RemoteMediaFetcher body budget', function (): void {
    it('aborts the transfer once the body passes the ceiling', function (): void {
        // The body is read in chunks and the transfer is cancelled at the
        // ceiling, so the rest of the body never arrives.
        $client = new WatchedHttpClient(str_repeat('x', 64 * 1024));

        expect(static fn() => makeFetcher($client, maxBytes: 1024)->fetch('https://93.184.216.34/big.png'))
            ->toThrow(RemoteMediaFetchException::class, 'max_promote_bytes');

        expect($client->response->cancelled)->toBeTrue();
        expect($client->chunksStreamed)->toBeLessThan(64);
    });
});

describe('RemoteMediaFetcher address pinning', function (): void {
    it('pins a hostname request to the address the guard vetted', function (): void {
        $vetted = SsrfGuard::forWebFetch()->screen('https://example.com/pinned')['resolve'];

        if ($vetted === []) {
            $this->markTestSkipped('This environment resolves no public name.');
        }

        $client = new WatchedHttpClient('body');

        makeFetcher($client)->fetch('https://example.com/pinned');

        $options = $client->requests[0]['options'] ?? [];

        // The name resolves to a public address now, and the request has
        // to go there rather than to whatever the name answers with a
        // moment later.
        expect($options['resolve'] ?? null)->toHaveKey('example.com');
        expect(filter_var($options['resolve']['example.com'], FILTER_VALIDATE_IP))->not->toBeFalse();
    });

    it('pins the redirect hop to the address that hop vetted', function (): void {
        $guard = SsrfGuard::forWebFetch();
        $first = $guard->screen('https://example.com/redirect.png')['resolve'];
        $second = $guard->screen('https://example.net/final.png')['resolve'];

        if ($first === [] || $second === []) {
            $this->markTestSkipped('This environment resolves no public name.');
        }

        $client = new WatchedHttpClient('body', redirectTo: 'https://example.net/final.png');

        makeFetcher($client)->fetch('https://example.com/redirect.png');

        // A redirect target is a destination of its own: each hop is
        // screened, and each hop is pinned to the address its own screen
        // vetted.
        expect($client->requests)->toHaveCount(2);
        expect($client->requests[0]['options']['resolve'] ?? null)->toHaveKey('example.com');
        expect($client->requests[1]['url'])->toBe('https://example.net/final.png');
        expect($client->requests[1]['options']['resolve'] ?? null)->toHaveKey('example.net');
    });
});

/**
 * A response the test can watch: it records the cancellation the fetcher
 * performs when the body passes the ceiling.
 */
final class WatchedResponse implements ResponseInterface
{
    public bool $cancelled = false;

    /** @param array<string, mixed> $info */
    public function __construct(
        private readonly string $body,
        private readonly array $info = [],
    ) {}

    public function getStatusCode(): int
    {
        return (int) ($this->info['http_code'] ?? 200);
    }

    public function getHeaders(bool $throw = true): array
    {
        return ['content-type' => ['image/png']];
    }

    public function getContent(bool $throw = true): string
    {
        return $this->body;
    }

    public function toArray(bool $throw = true): array
    {
        return [];
    }

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function getInfo(?string $type = null): mixed
    {
        return null === $type ? $this->info : ($this->info[$type] ?? null);
    }
}

/**
 * A client that hands out one response and streams it in chunks, so the
 * test can see how much of the body the fetcher actually read.
 */
final class WatchedHttpClient implements HttpClientInterface
{
    public WatchedResponse $response;

    public int $chunksStreamed = 0;

    /**
     * @var list<array{method: string, url: string, options: array<string, mixed>}>
     */
    public array $requests = [];

    private ?WatchedResponse $redirect = null;

    public function __construct(string $body, ?string $redirectTo = null)
    {
        $this->response = new WatchedResponse($body);
        if ($redirectTo !== null) {
            $this->redirect = new WatchedResponse('', ['http_code' => 302, 'redirect_url' => $redirectTo]);
        }
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->requests[] = [
            'method'  => $method,
            'url'     => $url,
            'options' => $options,
        ];

        $redirect = $this->redirect;
        $this->redirect = null;

        return $redirect ?? $this->response;
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        $chunks = [];
        $offset = 0;
        foreach (str_split($this->response->getContent(), 1024) as $chunk) {
            $chunks[] = new DataChunk($offset, $chunk);
            $offset += strlen($chunk);
        }

        return new class ($chunks, $this->response, $this) implements ResponseStreamInterface {
            private int $position = 0;

            /** @param list<ChunkInterface> $chunks */
            public function __construct(
                private readonly array $chunks,
                private readonly ResponseInterface $response,
                private readonly WatchedHttpClient $client,
            ) {}

            public function key(): ResponseInterface
            {
                return $this->response;
            }

            public function current(): ChunkInterface
            {
                return $this->chunks[$this->position];
            }

            public function next(): void
            {
                ++$this->position;
                ++$this->client->chunksStreamed;
            }

            public function rewind(): void
            {
                $this->position = 0;
            }

            public function valid(): bool
            {
                return isset($this->chunks[$this->position]);
            }
        };
    }

    public function withOptions(array $options): static
    {
        return $this;
    }
}
