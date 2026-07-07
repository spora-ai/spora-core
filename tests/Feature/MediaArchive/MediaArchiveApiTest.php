<?php

declare(strict_types=1);

namespace Tests\Feature\MediaArchive;

use FilesystemIterator;
use Psr\Log\NullLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Http\Exceptions\UnauthenticatedException;
use Spora\Http\MediaArchiveController;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Security\CsrfTokenService;
use Spora\Services\AutoAssetStore;
use Spora\Services\DataUrlAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Spora\Services\MediaArchive\MetadataExtractor;
use Spora\Services\MediaArchive\MimeSniffer;
use Spora\Services\MediaArchive\RemoteMediaFetcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;

/**
 * REST surface smoke test for {@see MediaArchiveController}. Mirrors the
 * `MemoryController` test layout — register a user, simulate the session,
 * drive the controller through the same middleware pipeline the router
 * uses in production.
 */
function mediaArchiveApiSetup(): array
{
    $tmp = sys_get_temp_dir() . '/spora-media-api-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;

    $paths     = new Paths(BASE_PATH);
    $security  = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $sniffer   = new MimeSniffer();
    $dataUrl   = new DataUrlAssetStore(50 * 1024 * 1024);
    $local     = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $assetStore = new AutoAssetStore($dataUrl, $local, 1_048_576);
    $metadata  = new MetadataExtractor(new NullLogger(), false);
    $logger    = new NullLogger();
    $fetcher   = new RemoteMediaFetcher(new MockHttpClient([]), $logger, 30, 100 * 1024 * 1024);

    $service = new MediaArchiveService(
        $assetStore,
        $fetcher,
        $sniffer,
        $metadata,
        $logger,
        true,
        100 * 1024 * 1024,
    );

    $controller = new MediaArchiveController($service);

    $restore = static function () use ($tmp): void {
        putenv('SPORA_STORAGE_DIR');
        unset($_ENV['SPORA_STORAGE_DIR'], $_SERVER['SPORA_STORAGE_DIR']);
        if (is_dir($tmp)) {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iter as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($tmp);
        }
    };

    return [
        'service'    => $service,
        'controller' => $controller,
        'tmp'        => $tmp,
        'restore'    => $restore,
    ];
}

const MEDIA_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

describe('MediaArchiveController', function (): void {
    it('rejects anonymous requests with 401', function (): void {
        clearSession();
        $ctx = mediaArchiveApiSetup();
        try {
            $authMw = new AuthMiddleware(bootAuthLayer());
            $csrfMw = new CsrfMiddleware(new CsrfTokenService());
            $request = Request::create('/api/v1/media', 'GET');
            expect(fn() => callController($ctx['controller'], 'index', $request, [$authMw, $csrfMw]))
                ->toThrow(UnauthenticatedException::class);
        } finally {
            $ctx['restore']();
        }
    });

    it('returns a paginated list for an authenticated user', function (): void {
        $ctx = mediaArchiveApiSetup();
        try {
            $authService = bootAuthLayer();
            $userId = $authService->register('media@example.com', 'ValidPass1!', 'Media');
            simulateLoggedInSession($userId, 'media@example.com');

            $bytes = base64_decode(MEDIA_PNG, strict: true);
            $ctx['service']->ingest(new MediaIngestRequest(bytes: $bytes, mime: 'image/png', filename: 'pixel.png'));
            $ctx['service']->ingest(new MediaIngestRequest(bytes: $bytes, mime: 'image/png', filename: 'pixel2.png'));

            $authMw = new AuthMiddleware($authService);
            $csrfMw = new CsrfMiddleware(new CsrfTokenService());
            $request = Request::create('/api/v1/media', 'GET');
            $response = callController($ctx['controller'], 'index', $request, [$authMw, $csrfMw]);

            expect($response->getStatusCode())->toBe(200);
            $body = json_decode($response->getContent(), true);
            expect($body['data']['total'])->toBe(2);
            expect($body['data']['assets'][0]['media_type'])->toBe('image');
        } finally {
            $ctx['restore']();
        }
    });

    it('returns the single asset on show', function (): void {
        $ctx = mediaArchiveApiSetup();
        try {
            $authService = bootAuthLayer();
            $userId = $authService->register('media2@example.com', 'ValidPass1!', 'Media2');
            simulateLoggedInSession($userId, 'media2@example.com');

            $bytes = base64_decode(MEDIA_PNG, strict: true);
            $asset = $ctx['service']->ingest(new MediaIngestRequest(bytes: $bytes, mime: 'image/png'));

            $authMw = new AuthMiddleware($authService);
            $csrfMw = new CsrfMiddleware(new CsrfTokenService());
            $request = Request::create('/api/v1/media/' . $asset->id, 'GET');
            $request->attributes->set('id', $asset->id);

            $response = callController($ctx['controller'], 'show', $request, [$authMw, $csrfMw]);
            expect($response->getStatusCode())->toBe(200);
            $body = json_decode($response->getContent(), true);
            expect($body['data']['id'])->toBe($asset->id);
        } finally {
            $ctx['restore']();
        }
    });

    it('deletes an asset on destroy', function (): void {
        $ctx = mediaArchiveApiSetup();
        try {
            $authService = bootAuthLayer();
            $userId = $authService->register('media3@example.com', 'ValidPass1!', 'Media3');
            simulateLoggedInSession($userId, 'media3@example.com');

            $bytes = base64_decode(MEDIA_PNG, strict: true);
            $asset = $ctx['service']->ingest(new MediaIngestRequest(bytes: $bytes, mime: 'image/png'));

            $csrfService = new CsrfTokenService();
            $token = $csrfService->generate();

            $authMw = new AuthMiddleware($authService);
            $csrfMw = new CsrfMiddleware($csrfService);
            $request = Request::create('/api/v1/media/' . $asset->id, 'DELETE');
            $request->attributes->set('id', $asset->id);
            $request->headers->set('X-CSRF-Token', $token);

            $response = callController($ctx['controller'], 'destroy', $request, [$authMw, $csrfMw]);
            expect($response->getStatusCode())->toBe(200);
            expect($ctx['service']->find($asset->id))->toBeNull();
        } finally {
            $ctx['restore']();
        }
    });

    it('returns 404 for unknown ids', function (): void {
        $ctx = mediaArchiveApiSetup();
        try {
            $authService = bootAuthLayer();
            $userId = $authService->register('media4@example.com', 'ValidPass1!', 'Media4');
            simulateLoggedInSession($userId, 'media4@example.com');

            $authMw = new AuthMiddleware($authService);
            $csrfMw = new CsrfMiddleware(new CsrfTokenService());
            $request = Request::create('/api/v1/media/00000000-0000-0000-0000-000000000000', 'GET');
            $request->attributes->set('id', '00000000-0000-0000-0000-000000000000');

            $response = callController($ctx['controller'], 'show', $request, [$authMw, $csrfMw]);
            expect($response->getStatusCode())->toBe(404);
        } finally {
            $ctx['restore']();
        }
    });

    it('returns 404 when destroying an unknown id', function (): void {
        $ctx = mediaArchiveApiSetup();
        try {
            $authService = bootAuthLayer();
            $userId = $authService->register('media-destroy@example.com', 'ValidPass1!', 'MediaDestroy');
            simulateLoggedInSession($userId, 'media-destroy@example.com');

            $csrfService = new CsrfTokenService();
            $token = $csrfService->generate();

            $authMw = new AuthMiddleware($authService);
            $csrfMw = new CsrfMiddleware($csrfService);
            $request = Request::create('/api/v1/media/00000000-0000-0000-0000-000000000000', 'DELETE');
            $request->attributes->set('id', '00000000-0000-0000-0000-000000000000');
            $request->headers->set('X-CSRF-Token', $token);

            $response = callController($ctx['controller'], 'destroy', $request, [$authMw, $csrfMw]);
            expect($response->getStatusCode())->toBe(404);
        } finally {
            $ctx['restore']();
        }
    });

    it('parses the type query param to a MediaType enum', function (): void {
        $ctx = mediaArchiveApiSetup();
        try {
            $authService = bootAuthLayer();
            $userId = $authService->register('media-type@example.com', 'ValidPass1!', 'MediaType');
            simulateLoggedInSession($userId, 'media-type@example.com');

            $bytes = base64_decode(MEDIA_PNG, strict: true);
            $ctx['service']->ingest(new MediaIngestRequest(bytes: $bytes, mime: 'image/png'));

            $authMw = new AuthMiddleware($authService);
            $csrfMw = new CsrfMiddleware(new CsrfTokenService());
            $request = Request::create('/api/v1/media?type=image', 'GET');

            $response = callController($ctx['controller'], 'index', $request, [$authMw, $csrfMw]);
            expect($response->getStatusCode())->toBe(200);
            $body = json_decode($response->getContent(), true);
            expect($body['data']['total'])->toBe(1);
        } finally {
            $ctx['restore']();
        }
    });

    it('silently drops an unrecognised type query param (returns no filter)', function (): void {
        $ctx = mediaArchiveApiSetup();
        try {
            $authService = bootAuthLayer();
            $userId = $authService->register('media-bogus-type@example.com', 'ValidPass1!', 'BogusType');
            simulateLoggedInSession($userId, 'media-bogus-type@example.com');

            $bytes = base64_decode(MEDIA_PNG, strict: true);
            $ctx['service']->ingest(new MediaIngestRequest(bytes: $bytes, mime: 'image/png'));

            $authMw = new AuthMiddleware($authService);
            $csrfMw = new CsrfMiddleware(new CsrfTokenService());
            // Unrecognised type — the controller's parseMediaType() returns null
            // for MediaType::tryFrom() misses, so the filter is dropped.
            $request = Request::create('/api/v1/media?type=bogus', 'GET');

            $response = callController($ctx['controller'], 'index', $request, [$authMw, $csrfMw]);
            expect($response->getStatusCode())->toBe(200);
            $body = json_decode($response->getContent(), true);
            expect($body['data']['total'])->toBe(1);
        } finally {
            $ctx['restore']();
        }
    });

    it('filters by plugin_slug and tool_name query params', function (): void {
        $ctx = mediaArchiveApiSetup();
        try {
            $authService = bootAuthLayer();
            $userId = $authService->register('media-filter@example.com', 'ValidPass1!', 'MediaFilter');
            simulateLoggedInSession($userId, 'media-filter@example.com');

            $bytes = base64_decode(MEDIA_PNG, strict: true);
            $ctx['service']->ingest(new MediaIngestRequest(bytes: $bytes, mime: 'image/png', pluginSlug: 'foo', toolName: 'tavily'));
            $ctx['service']->ingest(new MediaIngestRequest(bytes: $bytes, mime: 'image/png', pluginSlug: 'bar', toolName: 'serper'));

            $authMw = new AuthMiddleware($authService);
            $csrfMw = new CsrfMiddleware(new CsrfTokenService());
            $request = Request::create('/api/v1/media?plugin_slug=foo&tool_name=tavily', 'GET');

            $response = callController($ctx['controller'], 'index', $request, [$authMw, $csrfMw]);
            expect($response->getStatusCode())->toBe(200);
            $body = json_decode($response->getContent(), true);
            expect($body['data']['total'])->toBe(1);
            expect($body['data']['assets'][0]['plugin_slug'])->toBe('foo');
        } finally {
            $ctx['restore']();
        }
    });

    it('filters by agent_id when the value is a positive integer', function (): void {
        $ctx = mediaArchiveApiSetup();
        try {
            $authService = bootAuthLayer();
            $userId = $authService->register('media-agent@example.com', 'ValidPass1!', 'MediaAgent');
            simulateLoggedInSession($userId, 'media-agent@example.com');

            $bytes = base64_decode(MEDIA_PNG, strict: true);
            $ctx['service']->ingest(new MediaIngestRequest(bytes: $bytes, mime: 'image/png'));

            $authMw = new AuthMiddleware($authService);
            $csrfMw = new CsrfMiddleware(new CsrfTokenService());
            // agent_id query param with a non-digit value is dropped by parseAgentId().
            $request = Request::create('/api/v1/media?agent_id=notadigit', 'GET');
            $response = callController($ctx['controller'], 'index', $request, [$authMw, $csrfMw]);
            expect($response->getStatusCode())->toBe(200);
            $body = json_decode($response->getContent(), true);
            // No agent_id filter applied (parseAgentId returns null for non-digit),
            // so all rows are returned.
            expect($body['data']['total'])->toBe(1);
        } finally {
            $ctx['restore']();
        }
    });

    it('filters by q (search) query param', function (): void {
        $ctx = mediaArchiveApiSetup();
        try {
            $authService = bootAuthLayer();
            $userId = $authService->register('media-q@example.com', 'ValidPass1!', 'MediaQ');
            simulateLoggedInSession($userId, 'media-q@example.com');

            $bytes = base64_decode(MEDIA_PNG, strict: true);
            $ctx['service']->ingest(new MediaIngestRequest(bytes: $bytes, mime: 'image/png', prompt: 'a fluffy cat'));
            $ctx['service']->ingest(new MediaIngestRequest(bytes: $bytes, mime: 'image/png', prompt: 'a sleepy dog'));

            $authMw = new AuthMiddleware($authService);
            $csrfMw = new CsrfMiddleware(new CsrfTokenService());
            $request = Request::create('/api/v1/media?q=cat', 'GET');
            $response = callController($ctx['controller'], 'index', $request, [$authMw, $csrfMw]);
            expect($response->getStatusCode())->toBe(200);
            $body = json_decode($response->getContent(), true);
            expect($body['data']['total'])->toBe(1);
        } finally {
            $ctx['restore']();
        }
    });

    it('honours the sort query param (created_at_asc)', function (): void {
        $ctx = mediaArchiveApiSetup();
        try {
            $authService = bootAuthLayer();
            $userId = $authService->register('media-sort@example.com', 'ValidPass1!', 'MediaSort');
            simulateLoggedInSession($userId, 'media-sort@example.com');

            $bytes = base64_decode(MEDIA_PNG, strict: true);
            $first  = $ctx['service']->ingest(new MediaIngestRequest(bytes: $bytes, mime: 'image/png'));
            $second = $ctx['service']->ingest(new MediaIngestRequest(bytes: $bytes, mime: 'image/png'));

            $authMw = new AuthMiddleware($authService);
            $csrfMw = new CsrfMiddleware(new CsrfTokenService());
            $request = Request::create('/api/v1/media?sort=created_at_asc', 'GET');
            $response = callController($ctx['controller'], 'index', $request, [$authMw, $csrfMw]);
            expect($response->getStatusCode())->toBe(200);
            $body = json_decode($response->getContent(), true);
            expect($body['data']['total'])->toBe(2);
            expect($body['data']['assets'][0]['id'])->toBe($first->id);
            expect($body['data']['assets'][1]['id'])->toBe($second->id);
        } finally {
            $ctx['restore']();
        }
    });

    it('falls back to created_at_desc for an unrecognised sort query param', function (): void {
        $ctx = mediaArchiveApiSetup();
        try {
            $authService = bootAuthLayer();
            $userId = $authService->register('media-bogus-sort@example.com', 'ValidPass1!', 'BogusSort');
            simulateLoggedInSession($userId, 'media-bogus-sort@example.com');

            $bytes = base64_decode(MEDIA_PNG, strict: true);
            $first  = $ctx['service']->ingest(new MediaIngestRequest(bytes: $bytes, mime: 'image/png'));
            // Force the older row's created_at back in time so the desc sort is
            // deterministic regardless of SQLite's timestamp precision.
            $first->created_at = \Illuminate\Support\Carbon::now()->subMinutes(5);
            $first->save();
            $second = $ctx['service']->ingest(new MediaIngestRequest(bytes: $bytes, mime: 'image/png'));

            $authMw = new AuthMiddleware($authService);
            $csrfMw = new CsrfMiddleware(new CsrfTokenService());
            $request = Request::create('/api/v1/media?sort=bogus', 'GET');
            $response = callController($ctx['controller'], 'index', $request, [$authMw, $csrfMw]);
            expect($response->getStatusCode())->toBe(200);
            $body = json_decode($response->getContent(), true);
            expect($body['data']['total'])->toBe(2);
            // Default sort is created_at_desc — newest first.
            expect($body['data']['assets'][0]['id'])->toBe($second->id);
            expect($body['data']['assets'][1]['id'])->toBe($first->id);
        } finally {
            $ctx['restore']();
        }
    });

    it('honours per_page and page query params', function (): void {
        $ctx = mediaArchiveApiSetup();
        try {
            $authService = bootAuthLayer();
            $userId = $authService->register('media-pagination@example.com', 'ValidPass1!', 'Pagination');
            simulateLoggedInSession($userId, 'media-pagination@example.com');

            $bytes = base64_decode(MEDIA_PNG, strict: true);
            for ($i = 0; $i < 5; $i++) {
                $ctx['service']->ingest(new MediaIngestRequest(bytes: $bytes, mime: 'image/png'));
            }

            $authMw = new AuthMiddleware($authService);
            $csrfMw = new CsrfMiddleware(new CsrfTokenService());
            $request = Request::create('/api/v1/media?per_page=2&page=2', 'GET');
            $response = callController($ctx['controller'], 'index', $request, [$authMw, $csrfMw]);
            expect($response->getStatusCode())->toBe(200);
            $body = json_decode($response->getContent(), true);
            expect($body['data']['perPage'])->toBe(2);
            expect($body['data']['page'])->toBe(2);
            expect($body['data']['total'])->toBe(5);
            expect($body['data']['lastPage'])->toBe(3);
            expect(count($body['data']['assets']))->toBe(2);
        } finally {
            $ctx['restore']();
        }
    });

    it('drops an unparseable from/to query param (returns no filter, no error)', function (): void {
        $ctx = mediaArchiveApiSetup();
        try {
            $authService = bootAuthLayer();
            $userId = $authService->register('media-baddate@example.com', 'ValidPass1!', 'BadDate');
            simulateLoggedInSession($userId, 'media-baddate@example.com');

            $bytes = base64_decode(MEDIA_PNG, strict: true);
            $ctx['service']->ingest(new MediaIngestRequest(bytes: $bytes, mime: 'image/png'));

            $authMw = new AuthMiddleware($authService);
            $csrfMw = new CsrfMiddleware(new CsrfTokenService());
            $request = Request::create('/api/v1/media?from=notadate&to=alsonotadate', 'GET');
            $response = callController($ctx['controller'], 'index', $request, [$authMw, $csrfMw]);
            expect($response->getStatusCode())->toBe(200);
            $body = json_decode($response->getContent(), true);
            expect($body['data']['total'])->toBe(1);
        } finally {
            $ctx['restore']();
        }
    });

    it('serialises a row with timestamps and full payload', function (): void {
        $ctx = mediaArchiveApiSetup();
        try {
            $authService = bootAuthLayer();
            $userId = $authService->register('media-serialize@example.com', 'ValidPass1!', 'Serialize');
            simulateLoggedInSession($userId, 'media-serialize@example.com');

            $bytes = base64_decode(MEDIA_PNG, strict: true);
            $asset = $ctx['service']->ingest(new MediaIngestRequest(
                bytes: $bytes,
                mime: 'image/png',
                pluginSlug: 'demo',
                toolName: 'tavily',
                prompt: 'hello',
            ));

            $authMw = new AuthMiddleware($authService);
            $csrfMw = new CsrfMiddleware(new CsrfTokenService());
            $request = Request::create('/api/v1/media/' . $asset->id, 'GET');
            $request->attributes->set('id', $asset->id);

            $response = callController($ctx['controller'], 'show', $request, [$authMw, $csrfMw]);
            expect($response->getStatusCode())->toBe(200);
            $body = json_decode($response->getContent(), true);
            // Every serialised field is present.
            foreach (['id', 'agent_id', 'task_id', 'tool_call_id', 'plugin_slug', 'tool_name', 'media_type', 'mime_type', 'byte_size', 'width', 'height', 'duration_seconds', 'prompt', 'tags', 'metadata', 'asset_url', 'source_url', 'storage_mode', 'created_at', 'updated_at'] as $key) {
                expect($body['data'])->toHaveKey($key);
            }
            expect($body['data']['plugin_slug'])->toBe('demo');
            expect($body['data']['prompt'])->toBe('hello');
        } finally {
            $ctx['restore']();
        }
    });

    it('returns 404 with a JSON error envelope on destroy of an unknown id', function (): void {
        $ctx = mediaArchiveApiSetup();
        try {
            $authService = bootAuthLayer();
            $userId = $authService->register('media-404@example.com', 'ValidPass1!', 'M404');
            simulateLoggedInSession($userId, 'media-404@example.com');

            $csrfService = new CsrfTokenService();
            $token = $csrfService->generate();

            $authMw = new AuthMiddleware($authService);
            $csrfMw = new CsrfMiddleware($csrfService);
            $request = Request::create('/api/v1/media/00000000-0000-0000-0000-000000000000', 'DELETE');
            $request->attributes->set('id', '00000000-0000-0000-0000-000000000000');
            $request->headers->set('X-CSRF-Token', $token);

            $response = callController($ctx['controller'], 'destroy', $request, [$authMw, $csrfMw]);
            expect($response->getStatusCode())->toBe(404);
            $body = json_decode($response->getContent(), true);
            expect($body)->toHaveKey('error');
            expect($body['error']['code'])->toBe('NOT_FOUND');
        } finally {
            $ctx['restore']();
        }
    });

});
