<?php

declare(strict_types=1);

namespace Tests\Feature\GroupPictures;

use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Http\GroupPictureController;
use Spora\Models\GroupMembership;
use Spora\Models\GroupPicture;
use Spora\Services\AutoAssetStore;
use Spora\Services\DataUrlAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MimeSniffer;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Services\ProfilePictures\GroupPictureService;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Coverage tests for GroupPictureController that exercise the validation
 * paths (404, 403, 400, 413, 415) without going through the full
 * MediaArchiveService. The full upload happy path is covered here too
 * (a single valid PNG round-trip) so the wire shape is end-to-end.
 */
beforeEach(function (): void {
    $this->userId = bootAuth(bootAuthLayer());

    $this->groupId = \Illuminate\Database\Capsule\Manager::table('groups')->insertGetId([
        'name' => 'Group Pics',
        'description' => null,
        'created_by_user_id' => $this->userId,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    \Illuminate\Database\Capsule\Manager::table('group_memberships')->insert([
        'group_id' => $this->groupId,
        'user_id' => $this->userId,
        'role' => GroupMembership::ROLE_OWNER,
        'joined_at' => date('Y-m-d H:i:s'),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $this->principalId = (int) \Illuminate\Database\Capsule\Manager::table('principals')->insertGetId([
        'type' => 'group',
        'group_id' => $this->groupId,
        'user_id' => null,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
});

function setupGroupPictureStorage(): string
{
    $tmp = sys_get_temp_dir() . '/spora-group-picture-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;
    return $tmp;
}

function teardownGroupPictureStorage(string $tmp): void
{
    putenv('SPORA_STORAGE_DIR');
    unset($_ENV['SPORA_STORAGE_DIR'], $_SERVER['SPORA_STORAGE_DIR']);
    if (is_dir($tmp)) {
        foreach (glob($tmp . '/*') as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($tmp);
    }
}

function buildGroupPictureController(GroupPictureService $service): GroupPictureController
{
    $tmp = setupGroupPictureStorage();
    $paths = new Paths(BASE_PATH);
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $dataUrl = new DataUrlAssetStore(50 * 1024 * 1024);
    $local = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $assetStore = new AutoAssetStore($dataUrl, $local, 1_048_576);
    $mediaArchive = \Tests\Support\MediaArchiveTestSupport::buildService($assetStore);

    return new GroupPictureController(
        bootAuthLayer(),
        $service,
        new PrincipalService(new PrincipalResolver()),
        $mediaArchive,
        new MimeSniffer(),
    );
}

// 1x1 valid PNG (smallest possible image — 67 bytes)
const VALID_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

test('POST /groups/{id}/picture/image returns 404 when the group does not exist', function (): void {
    $req = Request::create('/api/v1/groups/99/picture/image', 'POST');
    $req->attributes->set('id', 99);

    $resp = buildGroupPictureController(new GroupPictureService())->uploadImage($req);

    expect($resp->getStatusCode())->toBe(404);
});

test('POST /groups/{id}/picture/image returns 403 when the caller is a member but not owner', function (): void {
    // Insert a non-owner member who is the logged-in user; the
    // authorisation gate requires owner-or-admin.
    \Illuminate\Database\Capsule\Manager::table('group_memberships')
        ->where('group_id', $this->groupId)
        ->where('user_id', $this->userId)
        ->update(['role' => GroupMembership::ROLE_MEMBER]);

    $req = Request::create("/api/v1/groups/{$this->groupId}/picture/image", 'POST');
    $req->attributes->set('id', $this->groupId);

    $resp = buildGroupPictureController(new GroupPictureService())->uploadImage($req);

    expect($resp->getStatusCode())->toBe(403);
});

test('POST /groups/{id}/picture/image returns 400 when no file is uploaded', function (): void {
    $req = Request::create("/api/v1/groups/{$this->groupId}/picture/image", 'POST');
    $req->attributes->set('id', $this->groupId);

    $resp = buildGroupPictureController(new GroupPictureService())->uploadImage($req);

    expect($resp->getStatusCode())->toBe(400);
    expect(json_decode($resp->getContent(), true)['error']['code'])->toBe('BAD_REQUEST');
});

test('POST /groups/{id}/picture/image returns 413 when the file is too large', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'spora-group-pic-big');
    file_put_contents($tmp, str_repeat('A', 2 * 1024 * 1024));
    $uploaded = new UploadedFile($tmp, 'avatar.png', 'image/png', null, true);
    $req = Request::create("/api/v1/groups/{$this->groupId}/picture/image", 'POST');
    $req->files->set('file', $uploaded);
    $req->attributes->set('id', $this->groupId);

    $resp = buildGroupPictureController(new GroupPictureService())->uploadImage($req);

    expect($resp->getStatusCode())->toBe(413);
    expect(json_decode($resp->getContent(), true)['error']['code'])->toBe('PAYLOAD_TOO_LARGE');
});

test('POST /groups/{id}/picture/image returns 415 when the bytes are not a raster image', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'spora-group-pic-pdf');
    file_put_contents($tmp, '%PDF-1.4' . str_repeat("\x00", 256));
    $uploaded = new UploadedFile($tmp, 'avatar.png', 'image/png', null, true);
    $req = Request::create("/api/v1/groups/{$this->groupId}/picture/image", 'POST');
    $req->files->set('file', $uploaded);
    $req->attributes->set('id', $this->groupId);

    $resp = buildGroupPictureController(new GroupPictureService())->uploadImage($req);

    expect($resp->getStatusCode())->toBe(415);
    expect(json_decode($resp->getContent(), true)['error']['code'])->toBe('UNSUPPORTED_MEDIA_TYPE');
    expect(GroupPicture::where('group_id', $this->groupId)->first())->toBeNull();
});

test('POST /groups/{id}/picture/image accepts a valid PNG and persists the group_pictures row', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'spora-group-pic-real');
    file_put_contents($tmp, base64_decode(VALID_PNG_BASE64));
    $uploaded = new UploadedFile($tmp, 'avatar.png', 'image/png', null, true);
    $req = Request::create("/api/v1/groups/{$this->groupId}/picture/image", 'POST');
    $req->files->set('file', $uploaded);
    $req->attributes->set('id', $this->groupId);

    $resp = buildGroupPictureController(new GroupPictureService())->uploadImage($req);

    expect($resp->getStatusCode())->toBe(201);

    $picture = GroupPicture::where('group_id', $this->groupId)->first();
    expect($picture)->not->toBeNull();
    expect($picture->media_asset_id)->not->toBeNull();
    // The default archetype is preserved on attach so a follow-up DELETE
    // restores the operator's prior archetype/palette choice.
    expect($picture->archetype)->toBe('collaborative');
    expect($picture->palette_key)->toBe('slate');

    $body = json_decode($resp->getContent(), true);
    expect($body['data']['group']['profile_picture']['kind'])->toBe('image');
    expect($body['data']['group']['profile_picture']['image_url'])->not->toBeNull();
});

test('DELETE /groups/{id}/picture/image returns 404 when the group does not exist', function (): void {
    $req = Request::create('/api/v1/groups/99/picture/image', 'DELETE');
    $req->attributes->set('id', 99);

    $resp = buildGroupPictureController(new GroupPictureService())->deleteImage($req);

    expect($resp->getStatusCode())->toBe(404);
});

test('DELETE /groups/{id}/picture/image returns 403 when the caller is a member but not owner', function (): void {
    \Illuminate\Database\Capsule\Manager::table('group_memberships')
        ->where('group_id', $this->groupId)
        ->where('user_id', $this->userId)
        ->update(['role' => GroupMembership::ROLE_MEMBER]);

    $req = Request::create("/api/v1/groups/{$this->groupId}/picture/image", 'DELETE');
    $req->attributes->set('id', $this->groupId);

    $resp = buildGroupPictureController(new GroupPictureService())->deleteImage($req);

    expect($resp->getStatusCode())->toBe(403);
});

test('DELETE /groups/{id}/picture/image clears the image and preserves the previous avatar', function (): void {
    $service = new GroupPictureService();
    $service->updateAvatar($this->groupId, 'researcher', 'v1', 'violet');

    $req = Request::create("/api/v1/groups/{$this->groupId}/picture/image", 'DELETE');
    $req->attributes->set('id', $this->groupId);

    $resp = buildGroupPictureController($service)->deleteImage($req);

    expect($resp->getStatusCode())->toBe(200);
    $picture = GroupPicture::where('group_id', $this->groupId)->first();
    expect($picture->archetype)->toBe('researcher');
    expect($picture->palette_key)->toBe('violet');
});

test('DELETE /groups/{id}/picture/image falls back to defaults when no avatar was set', function (): void {
    $service = new GroupPictureService();
    // No prior updateAvatar — getOrCreate seeds the defaults.

    $req = Request::create("/api/v1/groups/{$this->groupId}/picture/image", 'DELETE');
    $req->attributes->set('id', $this->groupId);

    $resp = buildGroupPictureController($service)->deleteImage($req);

    expect($resp->getStatusCode())->toBe(200);
    $picture = GroupPicture::where('group_id', $this->groupId)->first();
    expect($picture->archetype)->toBe('collaborative');
    expect($picture->palette_key)->toBe('slate');
});
