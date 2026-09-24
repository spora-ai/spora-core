<?php

declare(strict_types=1);

namespace Tests\Feature\UserPictures;

use Spora\Core\Paths;
use Spora\Http\UserPictureController;
use Spora\Models\UserPicture;
use Spora\Services\MediaArchive\MimeSniffer;
use Spora\Services\UserPictures\UserPictureService;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Coverage tests for UserPictureController. Mirrors the validation
 * paths exercised by {@see Tests\Feature\AgentPictures\AgentPictureControllerTest}
 * (400 / 413 / 415 / 201 / 200 / 204) but on the `/me/picture*` surface
 * — there is no per-id authorisation because the only legitimate
 * writer is the caller.
 */
beforeEach(function (): void {
    $this->userId = bootAuth(bootAuthLayer());
    $this->tmp = setupUserPictureStorage();
});

function setupUserPictureStorage(): string
{
    $tmp = sys_get_temp_dir() . '/spora-user-picture-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    mkdir($tmp . '/user-pictures', 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;
    return $tmp;
}

function teardownUserPictureStorage(string $tmp): void
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

function buildUserPictureController(): UserPictureController
{
    $paths = new Paths(BASE_PATH);
    $service = new UserPictureService($paths);

    return new UserPictureController(
        bootAuthLayer(),
        $service,
        new MimeSniffer(),
    );
}

// 1x1 valid PNG (smallest possible image — 67 bytes)
const USER_PICTURE_VALID_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

afterEach(function (): void {
    teardownUserPictureStorage($this->tmp);
});

test('GET /me/picture returns null profile_picture when no upload exists', function (): void {
    $resp = buildUserPictureController()->show();

    expect($resp->getStatusCode())->toBe(200);
    $data = json_decode($resp->getContent(), true)['data'];
    expect($data['profile_picture'])->toBeNull();
});

test('POST /me/picture/image returns 400 when no file is uploaded', function (): void {
    $req = Request::create('/api/v1/me/picture/image', 'POST');
    $resp = buildUserPictureController()->uploadImage($req);

    expect($resp->getStatusCode())->toBe(400);
    $body = json_decode($resp->getContent(), true);
    expect($body['error']['code'])->toBe('BAD_REQUEST');
});

test('POST /me/picture/image returns 413 when the file is too large', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'spora-user-pic-big');
    file_put_contents($tmp, str_repeat('A', 2 * 1024 * 1024));
    $uploaded = new UploadedFile($tmp, 'avatar.png', 'image/png', null, true);
    $req = Request::create('/api/v1/me/picture/image', 'POST');
    $req->files->set('file', $uploaded);

    $resp = buildUserPictureController()->uploadImage($req);

    expect($resp->getStatusCode())->toBe(413);
    expect(json_decode($resp->getContent(), true)['error']['code'])->toBe('PAYLOAD_TOO_LARGE');
    expect(UserPicture::where('user_id', $this->userId)->first())->toBeNull();
});

test('POST /me/picture/image returns 415 when the bytes are not an image', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'spora-user-pic-text');
    file_put_contents($tmp, "Hello, plain text payload.\n" . str_repeat('a', 64));
    $uploaded = new UploadedFile($tmp, 'avatar.txt', 'text/plain', null, true);
    $req = Request::create('/api/v1/me/picture/image', 'POST');
    $req->files->set('file', $uploaded);

    $resp = buildUserPictureController()->uploadImage($req);

    expect($resp->getStatusCode())->toBe(415);
    expect(json_decode($resp->getContent(), true)['error']['code'])->toBe('UNSUPPORTED_MEDIA_TYPE');
    expect(UserPicture::where('user_id', $this->userId)->first())->toBeNull();
});

test('POST /me/picture/image returns 415 when bytes lie (client says png, content is a PDF)', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'spora-user-pic-pdf');
    file_put_contents($tmp, '%PDF-1.4' . str_repeat("\x00", 256));
    $uploaded = new UploadedFile($tmp, 'avatar.png', 'image/png', null, true);
    $req = Request::create('/api/v1/me/picture/image', 'POST');
    $req->files->set('file', $uploaded);

    $resp = buildUserPictureController()->uploadImage($req);

    expect($resp->getStatusCode())->toBe(415);
    expect(UserPicture::where('user_id', $this->userId)->first())->toBeNull();
});

test('POST /me/picture/image accepts a valid PNG and persists the user_pictures row + file', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'spora-user-pic-png');
    file_put_contents($tmp, base64_decode(USER_PICTURE_VALID_PNG_BASE64));
    $uploaded = new UploadedFile($tmp, 'avatar.png', 'image/png', null, true);
    $req = Request::create('/api/v1/me/picture/image', 'POST');
    $req->files->set('file', $uploaded);

    $resp = buildUserPictureController()->uploadImage($req);

    expect($resp->getStatusCode())->toBe(201);
    $body = json_decode($resp->getContent(), true)['data'];
    expect($body['profile_picture']['kind'])->toBe('image');
    expect($body['profile_picture']['archetype'])->toBeNull();
    expect($body['profile_picture']['image_url'])->toBe("/api/v1/users/{$this->userId}/picture");
    expect($body['profile_picture']['image_updated_at'])->not->toBeNull();

    $row = UserPicture::where('user_id', $this->userId)->first();
    expect($row)->not->toBeNull();
    expect($row->mime)->toBe('image/png');
    expect($row->media_path)->toBe("user-pictures/{$this->userId}.png");
    expect(is_file($this->tmp . '/user-pictures/' . $this->userId . '.png'))->toBeTrue();
});

test('POST /me/picture/image replaces an existing picture atomically', function (): void {
    $tmp1 = tempnam(sys_get_temp_dir(), 'spora-user-pic-first');
    file_put_contents($tmp1, base64_decode(USER_PICTURE_VALID_PNG_BASE64));
    $req1 = Request::create('/api/v1/me/picture/image', 'POST');
    $req1->files->set('file', new UploadedFile($tmp1, 'first.png', 'image/png', null, true));
    buildUserPictureController()->uploadImage($req1);

    expect(UserPicture::where('user_id', $this->userId)->count())->toBe(1);

    // Second upload — should be an UPDATE on the existing row, not a new INSERT
    $tmp2 = tempnam(sys_get_temp_dir(), 'spora-user-pic-second');
    file_put_contents($tmp2, base64_decode(USER_PICTURE_VALID_PNG_BASE64));
    $req2 = Request::create('/api/v1/me/picture/image', 'POST');
    $req2->files->set('file', new UploadedFile($tmp2, 'second.png', 'image/png', null, true));
    $resp = buildUserPictureController()->uploadImage($req2);

    expect($resp->getStatusCode())->toBe(201);
    expect(UserPicture::where('user_id', $this->userId)->count())->toBe(1);
});

test('POST /me/picture/image accepts a valid WebP', function (): void {
    // 1x1 valid WebP (smallest possible — RIFF container with VP8 chunk).
    $bytes = "RIFF\x1a\x00\x00\x00WEBPVP8L\x0d\x00\x00\x00\x2f\x00\x00\x00\x00\x00\x80\x3f\x00\x00\x00\x00\x00";
    $tmp = tempnam(sys_get_temp_dir(), 'spora-user-pic-webp');
    file_put_contents($tmp, $bytes);
    $uploaded = new UploadedFile($tmp, 'avatar.webp', 'image/webp', null, true);
    $req = Request::create('/api/v1/me/picture/image', 'POST');
    $req->files->set('file', $uploaded);

    $resp = buildUserPictureController()->uploadImage($req);

    expect($resp->getStatusCode())->toBe(201);
    expect(UserPicture::where('user_id', $this->userId)->first()->mime)->toBe('image/webp');
});

test('GET /me/picture returns the wire shape after upload', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'spora-user-pic-show');
    file_put_contents($tmp, base64_decode(USER_PICTURE_VALID_PNG_BASE64));
    $reqPost = Request::create('/api/v1/me/picture/image', 'POST');
    $reqPost->files->set('file', new UploadedFile($tmp, 'avatar.png', 'image/png', null, true));
    buildUserPictureController()->uploadImage($reqPost);

    $resp = buildUserPictureController()->show();

    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode($resp->getContent(), true)['data'];
    expect($body['profile_picture'])->not->toBeNull();
    expect($body['profile_picture']['kind'])->toBe('image');
    expect($body['profile_picture']['image_url'])->toBe("/api/v1/users/{$this->userId}/picture");
});

test('DELETE /me/picture/image clears the row and the file', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'spora-user-pic-delete');
    file_put_contents($tmp, base64_decode(USER_PICTURE_VALID_PNG_BASE64));
    $reqPost = Request::create('/api/v1/me/picture/image', 'POST');
    $reqPost->files->set('file', new UploadedFile($tmp, 'avatar.png', 'image/png', null, true));
    buildUserPictureController()->uploadImage($reqPost);

    expect(UserPicture::where('user_id', $this->userId)->exists())->toBeTrue();

    $resp = buildUserPictureController()->deleteImage();

    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode($resp->getContent(), true)['data'];
    expect($body['profile_picture'])->toBeNull();
    expect(UserPicture::where('user_id', $this->userId)->exists())->toBeFalse();
});

test('DELETE /me/picture/image is idempotent when no upload exists', function (): void {
    $resp = buildUserPictureController()->deleteImage();

    expect($resp->getStatusCode())->toBe(200);
});
