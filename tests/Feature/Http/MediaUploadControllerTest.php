<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Http\MediaUploadController;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaAllowedTypesService;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaConverterDiscovery;
use Spora\Services\MediaArchive\MimeSniffer;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\MediaArchiveTestSupport;

afterEach(function (): void {
    MediaConverterDiscovery::reset();
});

/**
 * Plan §12 B2b — MediaUploadController end-to-end surface tests.
 */
test('multipart upload with a text file populates markdown_content via PlainTextPassthroughConverter', function (): void {
    [, $service, , , , $controller] = buildUploadControllerFixtures();
    $tmp = tempnam(sys_get_temp_dir(), 'txt');
    file_put_contents($tmp, "hello\nworld");
    $req = Request::create('/api/v1/media', 'POST', files: [
        'file' => new UploadedFile($tmp, 'doc.txt', 'text/plain', null, true),
    ]);
    $resp = $controller->store($req);
    expect($resp->getStatusCode())->toBe(Response::HTTP_CREATED);
    $body = json_decode($resp->getContent(), true);
    $asset = $service->find($body['data']['id']);
    expect($asset)->not->toBeNull();
    // PlainTextPassthroughConverter returns the bytes verbatim (trimmed).
    expect($asset->markdown_content)->not->toBeNull();
    expect($asset->markdown_content)->toContain('hello');
    unlink($tmp);
});

test('upload returns 415 on a disallowed executable MIME', function (): void {
    [, , , , , $controller] = buildUploadControllerFixtures();
    $tmp = tempnam(sys_get_temp_dir(), 'exe');
    file_put_contents($tmp, "MZ" . str_repeat("\0", 100));
    $req = Request::create('/api/v1/media', 'POST', files: [
        'file' => new UploadedFile($tmp, 'evil.exe', 'application/x-msdownload', null, true),
    ]);
    $resp = $controller->store($req);
    expect($resp->getStatusCode())->toBe(Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
    unlink($tmp);
});

test('upload sniffs MIME and overrides client-supplied Content-Type', function (): void {
    [, $service, , , , $controller] = buildUploadControllerFixtures();
    $tmp = tempnam(sys_get_temp_dir(), 'spoof');
    // Bytes start with the PDF magic
    file_put_contents($tmp, "%PDF-1.4 hello");
    $req = Request::create('/api/v1/media', 'POST', files: [
        'file' => new UploadedFile($tmp, 'evil.pdf', 'text/plain', null, true),
    ]);
    $resp = $controller->store($req);
    expect($resp->getStatusCode())->toBe(Response::HTTP_CREATED);
    $body = json_decode($resp->getContent(), true);
    $asset = $service->find($body['data']['id']);
    expect($asset->mime_type)->toBe('application/pdf');
    unlink($tmp);
});

test('upload records user_id from auth and upload_source=upload', function (): void {
    [, $service, $auth, , , $controller] = buildUploadControllerFixtures();
    $tmp = tempnam(sys_get_temp_dir(), 'src');
    file_put_contents($tmp, "hello");
    $req = Request::create('/api/v1/media', 'POST', files: [
        'file' => new UploadedFile($tmp, 'hello.txt', 'text/plain', null, true),
    ]);
    $resp = $controller->store($req);
    expect($resp->getStatusCode())->toBe(Response::HTTP_CREATED);
    $body = json_decode($resp->getContent(), true);
    $asset = $service->find($body['data']['id']);
    expect($asset->user_id)->toBe($auth->currentUserId());
    expect($asset->upload_source)->toBe('upload');
    unlink($tmp);
});

test('upload returns 401 when not authenticated', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'noauth');
    file_put_contents($tmp, "hello");
    [, , , , , $controller] = buildUploadControllerFixtures(buildAnonAuth());
    $req = Request::create('/api/v1/media', 'POST', files: [
        'file' => new UploadedFile($tmp, 'hello.txt', 'text/plain', null, true),
    ]);
    $resp = $controller->store($req);
    expect($resp->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
    unlink($tmp);
});

/**
 * @return array{0: AutoAssetStore, 1: MediaArchiveService, 2: \Spora\Auth\AuthService, 3: MediaAllowedTypesService, 4: MimeSniffer, 5: MediaUploadController}
 */
function buildUploadControllerFixtures(?\Spora\Auth\AuthService $auth = null): array
{
    $tmp = sys_get_temp_dir() . '/spora-upload-ctrl-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;
    $paths    = new Paths(BASE_PATH);
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $database = new DatabaseAssetStore(50 * 1024 * 1024);
    $local    = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $assetStore = new AutoAssetStore($database, $local, 1_048_576);
    $service = MediaArchiveTestSupport::buildService($assetStore);
    $auth ??= MediaArchiveTestSupport::buildAuth();
    $registry = MediaArchiveTestSupport::buildConverterRegistry();
    $allowed = new MediaAllowedTypesService($registry, new \Spora\Drivers\DriverFactory(
        new \Psr\Log\NullLogger(),
        new \Spora\Services\LLMConfigService(new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), []),
        300,
    ));
    $sniffer = new MimeSniffer();
    $controller = new MediaUploadController($service, $allowed, $auth, $sniffer);
    return [$assetStore, $service, $auth, $allowed, $sniffer, $controller];
}

function buildAnonAuth(): \Spora\Auth\AuthService
{
    return new class extends \Spora\Auth\AuthService {
        public function __construct()
        { /* no-op */
        }
        public function currentUserId(): ?int
        {
            return null;
        }
        public function isAdmin(): bool
        {
            return false;
        }
    };
}
