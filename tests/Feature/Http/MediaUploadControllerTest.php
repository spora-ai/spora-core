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

test('upload with a Latin-1 filename persists a clean UTF-8 filename', function (): void {
    [, $service, , , , $controller] = buildUploadControllerFixtures();
    $tmp = tempnam(sys_get_temp_dir(), 'latin1');
    file_put_contents($tmp, "hello");
    // é (0xE9) ü (0xFC) — Windows-1252 bytes that the sanitize path must repair.
    $latin1Name = 'résumé' . chr(0xE9) . chr(0xFC) . '.txt';
    $req = Request::create('/api/v1/media', 'POST', files: [
        'file' => new UploadedFile($tmp, $latin1Name, 'text/plain', null, true),
    ]);
    $resp = $controller->store($req);
    expect($resp->getStatusCode())->toBe(Response::HTTP_CREATED);
    $body = json_decode($resp->getContent(), true);
    $asset = $service->find($body['data']['id']);
    expect($asset)->not->toBeNull();
    // Persisted filename must be valid UTF-8 (the wrap at the
    // MediaIngestRequest construction site ensures this).
    expect(mb_check_encoding($asset->filename, 'UTF-8'))->toBeTrue();
    // And the JSON response must round-trip cleanly — that's the
    // invariant this whole utility exists for.
    expect(mb_check_encoding($body['data']['filename'], 'UTF-8'))->toBeTrue();
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

test('upload with principal_id set to a visible principal stamps principal_id on the row', function (): void {
    [, $service, $auth, , , $controller] = buildUploadControllerFixtures();
    $userId = (int) $auth->currentUserId();
    // Materialise the user row + principal explicitly so the
    // PrincipalService::ensureUserPrincipal() path doesn't try to
    // create the user (it requires the user row to exist already).
    seedAuthUserRow($userId);
    $principalId = (new \Spora\Services\PrincipalService(new \Spora\Services\PrincipalResolver()))
        ->ensureUserPrincipal($userId)->id;

    $tmp = tempnam(sys_get_temp_dir(), 'vis');
    file_put_contents($tmp, "hello");
    $req = Request::create('/api/v1/media', 'POST', [
        'principal_id' => (string) $principalId,
    ], files: [
        'file' => new UploadedFile($tmp, 'vis.txt', 'text/plain', null, true),
    ]);
    $resp = $controller->store($req);
    expect($resp->getStatusCode())->toBe(Response::HTTP_CREATED);
    $body = json_decode($resp->getContent(), true);
    $asset = $service->find($body['data']['id']);
    expect($asset->principal_id)->toBe($principalId);
    expect($body['data']['principal_id'])->toBe($principalId);
    unlink($tmp);
});

test('upload with principal_id set to a foreign principal returns 403 FORBIDDEN_PRINCIPAL', function (): void {
    [, $service, $auth, , , $controller] = buildUploadControllerFixtures();
    $userId = (int) $auth->currentUserId();
    seedAuthUserRow($userId);
    $otherUserId = $userId + 999;
    seedAuthUserRow($otherUserId);
    // A principal the caller does not own.
    $foreignPrincipalId = (new \Spora\Services\PrincipalService(new \Spora\Services\PrincipalResolver()))
        ->ensureUserPrincipal($otherUserId)->id;

    $tmp = tempnam(sys_get_temp_dir(), 'foreign');
    file_put_contents($tmp, "hello");
    $req = Request::create('/api/v1/media', 'POST', [
        'principal_id' => (string) $foreignPrincipalId,
    ], files: [
        'file' => new UploadedFile($tmp, 'foreign.txt', 'text/plain', null, true),
    ]);
    $resp = $controller->store($req);
    expect($resp->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);
    $body = json_decode($resp->getContent(), true);
    expect($body['error']['code'])->toBe('FORBIDDEN_PRINCIPAL');
    // No row inserted.
    expect(\Spora\Models\MediaAsset::query()->where('filename', 'foreign.txt')->first())->toBeNull();
    unlink($tmp);
});

test('upload with principal_id=0 (non-numeric string) is silently ignored (legacy behaviour)', function (): void {
    [, $service, $auth, , , $controller] = buildUploadControllerFixtures();
    $userId = (int) $auth->currentUserId();

    $tmp = tempnam(sys_get_temp_dir(), 'bogus');
    file_put_contents($tmp, "hello");
    $req = Request::create('/api/v1/media', 'POST', [
        'principal_id' => 'abc',
    ], files: [
        'file' => new UploadedFile($tmp, 'bogus.txt', 'text/plain', null, true),
    ]);
    $resp = $controller->store($req);
    expect($resp->getStatusCode())->toBe(Response::HTTP_CREATED);
    $body = json_decode($resp->getContent(), true);
    $asset = $service->find($body['data']['id']);
    // No principal_id supplied — falls back to user-principal via the
    // ingest pipeline's user-id chain (or null if the user row doesn't
    // exist in the test fixture). Either is fine; the upload must
    // succeed and not 400.
    expect($asset)->not->toBeNull();
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
    $controller = new MediaUploadController($service, $allowed, $auth, new \Spora\Services\PrincipalResolver(), $sniffer);
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

/**
 * Insert a `users` row with the given id so PrincipalService's
 * `ensureUserPrincipal()` doesn't reject the call. The password hash
 * is a deterministic placeholder; tests don't authenticate against
 * it.
 */
function seedAuthUserRow(int $userId): void
{
    $pdo = \Illuminate\Database\Capsule\Manager::connection()->getPdo();
    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO users (id, email, password, username, verified, resettable, roles_mask, registered, created_at, updated_at) '
        . 'VALUES (?, ?, ?, ?, 1, 1, 0, ?, ?, ?)',
    );
    $email = sprintf('upload-test-%d@example.com', $userId);
    $now = time();
    $stmt->execute([
        $userId,
        $email,
        password_hash('Password1!', PASSWORD_BCRYPT),
        $email,
        $now,
        date('Y-m-d H:i:s', $now),
        date('Y-m-d H:i:s', $now),
    ]);
}
