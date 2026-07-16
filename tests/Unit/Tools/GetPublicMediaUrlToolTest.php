<?php

declare(strict_types=1);

use Spora\Auth\AuthService;
use Spora\Models\MediaAsset;
use Spora\Tools\GetPublicMediaUrlTool;
use Symfony\Component\HttpFoundation\Request;

/**
 * Coverage for {@see GetPublicMediaUrlTool}.
 *
 * The tool has two operations (get, share) and rejects malformed
 * arguments. Each branch below exercises one slice of the dispatch so
 * the `execute()` / `describeAction()` / `parseArguments()` /
 * `loadOwnedAsset()` / `resultForAction()` helpers are all reached.
 */
function seedPublicMediaAsset(?int $userId, ?string $publicToken = null): MediaAsset
{
    return MediaAsset::create([
        'id'                       => '11111111-aaaa-bbbb-cccc-222222222222',
        'asset_url'                => '/api/v1/assets/11111111-aaaa-bbbb-cccc-222222222222.png',
        'storage_mode'             => 'data_url',
        'mime_type'                => 'image/png',
        'media_type'               => 'image',
        'byte_size'                => 1024,
        'user_id'                  => $userId,
        'asset_token'              => str_repeat('z', 32),
        'public_access_token'      => $publicToken,
        'migrated_from_inline_data_url' => false,
    ]);
}

function makeAdminAuth(): AuthService
{
    $auth = Mockery::mock(AuthService::class);
    $auth->allows('currentUserId')->andReturn(1);
    $auth->allows('isAdmin')->andReturn(true);

    return $auth;
}

test('parseArguments rejects an empty media_id', function (): void {
    $tool = new GetPublicMediaUrlTool(makeAdminAuth());
    $result = $tool->execute([], 1, 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('media_id is required');
});

test('parseArguments rejects an unknown action', function (): void {
    $tool = new GetPublicMediaUrlTool(makeAdminAuth());
    $result = $tool->execute(['media_id' => 'abc', 'action' => 'delete'], 1, 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('action must be "get" or "share"');
});

test('loadOwnedAsset returns fail when the row does not exist', function (): void {
    $tool = new GetPublicMediaUrlTool(makeAdminAuth());
    $result = $tool->execute(['media_id' => '00000000-0000-0000-0000-000000000000'], 1, 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Media asset not found');
});

test('loadOwnedAsset returns fail when the current user is not logged in', function (): void {
    seedPublicMediaAsset(userId: 1, publicToken: 'token');
    $auth = Mockery::mock(AuthService::class);
    $auth->allows('currentUserId')->andReturn(null);
    $auth->allows('isAdmin')->andReturn(false);
    $tool = new GetPublicMediaUrlTool($auth);
    $result = $tool->execute(['media_id' => '11111111-aaaa-bbbb-cccc-222222222222'], 1, null);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('must be logged in');
});

test('loadOwnedAsset returns fail when the non-admin user does not own the asset', function (): void {
    seedPublicMediaAsset(userId: 99, publicToken: 'token');
    $auth = Mockery::mock(AuthService::class);
    $auth->allows('currentUserId')->andReturn(1);
    $auth->allows('isAdmin')->andReturn(false);
    $tool = new GetPublicMediaUrlTool($auth);
    $result = $tool->execute(['media_id' => '11111111-aaaa-bbbb-cccc-222222222222'], 1, 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('do not own');
});

test('resultForAction get fails when no public_access_token is set', function (): void {
    seedPublicMediaAsset(userId: 1, publicToken: null);
    $tool = new GetPublicMediaUrlTool(makeAdminAuth());
    $result = $tool->execute(['media_id' => '11111111-aaaa-bbbb-cccc-222222222222', 'action' => 'get'], 1, 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('not shared publicly');
});

test('resultForAction get returns the public URL when the token is set', function (): void {
    seedPublicMediaAsset(userId: 1, publicToken: 'deadbeef');
    $tool = new GetPublicMediaUrlTool(makeAdminAuth(), Request::create('/'));
    $result = $tool->execute(['media_id' => '11111111-aaaa-bbbb-cccc-222222222222', 'action' => 'get'], 1, 1);
    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('/api/v1/public/media/11111111-aaaa-bbbb-cccc-222222222222?token=deadbeef');
});

test('resultForAction share mints a token when none is set', function (): void {
    seedPublicMediaAsset(userId: 1, publicToken: null);
    $tool = new GetPublicMediaUrlTool(makeAdminAuth(), Request::create('/'));
    $result = $tool->execute(['media_id' => '11111111-aaaa-bbbb-cccc-222222222222', 'action' => 'share'], 1, 1);
    expect($result->success)->toBeTrue();
    $reloaded = MediaAsset::query()->find('11111111-aaaa-bbbb-cccc-222222222222');
    expect($reloaded->public_access_token)->not->toBeNull()
        ->and($reloaded->public_access_token)->not->toBe('');
});

test('resultForAction share keeps an existing token unchanged', function (): void {
    seedPublicMediaAsset(userId: 1, publicToken: 'pre-existing');
    $tool = new GetPublicMediaUrlTool(makeAdminAuth(), Request::create('/'));
    $tool->execute(['media_id' => '11111111-aaaa-bbbb-cccc-222222222222', 'action' => 'share'], 1, 1);
    $reloaded = MediaAsset::query()->find('11111111-aaaa-bbbb-cccc-222222222222');
    expect($reloaded->public_access_token)->toBe('pre-existing');
});

test('describeAction formats media_id and action', function (): void {
    $tool = new GetPublicMediaUrlTool(makeAdminAuth());
    $desc = $tool->describeAction(['media_id' => 'abc', 'action' => 'share']);
    expect($desc)->toBe('Public media URL (share) for abc');
});

test('describeAction defaults action to get when omitted', function (): void {
    $tool = new GetPublicMediaUrlTool(makeAdminAuth());
    $desc = $tool->describeAction(['media_id' => 'abc']);
    expect($desc)->toBe('Public media URL (get) for abc');
});

test('publicUrl falls back to HTTP_HOST when no request is provided', function (): void {
    seedPublicMediaAsset(userId: 1, publicToken: 'tkn');
    $_SERVER['HTTP_HOST'] = 'fallback.example';
    $tool = new GetPublicMediaUrlTool(makeAdminAuth(), null);
    $result = $tool->execute(['media_id' => '11111111-aaaa-bbbb-cccc-222222222222', 'action' => 'get'], 1, 1);
    expect($result->content)->toContain('fallback.example');
    unset($_SERVER['HTTP_HOST']);
});
