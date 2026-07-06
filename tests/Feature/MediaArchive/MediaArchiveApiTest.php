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
});
