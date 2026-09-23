<?php

declare(strict_types=1);

namespace Tests\Feature\UserPictures;

use Spora\Core\Paths;
use Spora\Http\UserPictureAssetController;
use Spora\Models\UserPicture;
use Spora\Services\UserPictures\UserPictureService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Coverage tests for UserPictureAssetController — the cross-user read
 * surface (`GET /api/v1/users/{id}/picture`). The visibility rule is
 * deliberately looser than {@see \Spora\Http\AssetController}: any
 * logged-in user can fetch any other user's picture. These tests
 * exercise that contract.
 */
beforeEach(function (): void {
    $this->userId = bootAuth(bootAuthLayer());
    $this->tmp = setupAssetStorage();
});

function setupAssetStorage(): string
{
    $tmp = sys_get_temp_dir() . '/spora-user-pic-asset-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    mkdir($tmp . '/user-pictures', 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;
    return $tmp;
}

function teardownAssetStorage(string $tmp): void
{
    putenv('SPORA_STORAGE_DIR');
    unset($_ENV['SPORA_STORAGE_DIR'], $_SERVER['SPORA_STORAGE_DIR']);
    if (is_dir($tmp)) {
        foreach (glob($tmp . '/**/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($tmp . '/user-pictures');
        @rmdir($tmp);
    }
}

function buildAssetController(): UserPictureAssetController
{
    $paths = new Paths(BASE_PATH);
    return new UserPictureAssetController(
        bootAuthLayer(),
        new UserPictureService($paths),
    );
}

afterEach(function (): void {
    teardownAssetStorage($this->tmp);
});

test('GET /users/{id}/picture returns 401 when not logged in', function (): void {
    $_SESSION = [];
    $resp = buildAssetController()->show(Request::create('/api/v1/users/1/picture', 'GET'), 1);

    expect($resp->getStatusCode())->toBe(401);
    $body = json_decode($resp->getContent(), true);
    expect($body['error']['code'])->toBe('UNAUTHENTICATED');
});

test('GET /users/{id}/picture returns 404 when the user has no picture', function (): void {
    $resp = buildAssetController()->show(Request::create('/api/v1/users/999/picture', 'GET'), 999);

    expect($resp->getStatusCode())->toBe(404);
});

test('GET /users/{id}/picture returns the bytes for the caller (own picture)', function (): void {
    $payload = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    $path = $this->tmp . '/user-pictures/' . $this->userId . '.png';
    file_put_contents($path, $payload);

    UserPicture::create([
        'user_id'    => $this->userId,
        'media_path' => 'user-pictures/' . $this->userId . '.png',
        'mime'       => 'image/png',
        'size_bytes' => strlen($payload),
    ]);

    $controller = buildAssetController();
    $resp = $controller->show(Request::create('/api/v1/users/' . $this->userId . '/picture', 'GET'), $this->userId);

    expect($resp->getStatusCode())->toBe(200);
    expect($resp->headers->get('Content-Type'))->toBe('image/png');
    expect($resp->headers->get('Cache-Control'))->toContain('max-age=86400')
        ->and($resp->headers->get('Cache-Control'))->toContain('private');

    ob_start();
    $resp->sendContent();
    $body = ob_get_clean();
    expect($body)->toBe($payload);
});

test('GET /users/{id}/picture returns the bytes for any other logged-in user', function (): void {
    // Owner of the picture — register via AuthService so the password
    // NOT NULL constraint and any other auth-table FKs are satisfied.
    $otherAuth = bootAuthLayer('', '');
    $ownerId = $otherAuth->register(
        'owner-' . bin2hex(random_bytes(3)) . '@example.com',
        'Password1!',
        'Owner',
    );

    $payload = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    file_put_contents($this->tmp . '/user-pictures/' . $ownerId . '.png', $payload);
    UserPicture::create([
        'user_id'    => $ownerId,
        'media_path' => 'user-pictures/' . $ownerId . '.png',
        'mime'       => 'image/png',
        'size_bytes' => strlen($payload),
    ]);

    // Caller is the authenticated user from beforeEach — a different user
    expect($ownerId)->not->toBe($this->userId);

    $resp = buildAssetController()->show(Request::create('/api/v1/users/' . $ownerId . '/picture', 'GET'), $ownerId);

    expect($resp->getStatusCode())->toBe(200);

    ob_start();
    $resp->sendContent();
    $body = ob_get_clean();
    expect($body)->toBe($payload);
});

test('GET /users/{id}/picture returns 404 when the row exists but the file is missing', function (): void {
    UserPicture::create([
        'user_id'    => $this->userId,
        'media_path' => 'user-pictures/' . $this->userId . '.png',
        'mime'       => 'image/png',
        'size_bytes' => 100,
    ]);
    // Deliberately do NOT create the on-disk file.

    $resp = buildAssetController()->show(Request::create('/api/v1/users/' . $this->userId . '/picture', 'GET'), $this->userId);

    expect($resp->getStatusCode())->toBe(404);
});
