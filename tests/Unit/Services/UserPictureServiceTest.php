<?php

declare(strict_types=1);

use Spora\Core\Paths;
use Spora\Models\UserPicture;
use Spora\Services\UserPictures\UserPictureService;
use Spora\Services\UserService;

/**
 * Verify that the user wire shape (returned by `/auth/me`,
 * `/auth/login`, `/admin/users*`) carries `profile_picture` so the
 * SPA can hydrate the navbar + AccountPage without a separate
 * round-trip.
 */
beforeEach(function (): void {
    $this->userId = bootAuth(bootAuthLayer());
});

test('UserService::getUser includes profile_picture in the wire shape', function (): void {
    $service = new UserService(new UserPictureService(new Paths(BASE_PATH)));

    $result = $service->getUser($this->userId);

    expect($result)->not->toBeNull();
    expect($result['user'])->toHaveKey('profile_picture');
    expect($result['user']['profile_picture'])->toBeNull();
});

test('UserService::getUser surfaces the uploaded picture in profile_picture', function (): void {
    $service = new UserService(new UserPictureService(new Paths(BASE_PATH)));

    UserPicture::create([
        'user_id'    => $this->userId,
        'media_path' => 'user-pictures/' . $this->userId . '.png',
        'mime'       => 'image/png',
        'size_bytes' => 100,
    ]);

    $result = $service->getUser($this->userId);

    expect($result['user']['profile_picture'])->not->toBeNull();
    expect($result['user']['profile_picture']['kind'])->toBe('image');
    expect($result['user']['profile_picture']['image_url'])->toBe("/api/v1/users/{$this->userId}/picture");
    expect($result['user']['profile_picture']['archetype'])->toBeNull();
    expect($result['user']['profile_picture']['variant_key'])->toBeNull();
    expect($result['user']['profile_picture']['palette_key'])->toBeNull();
});

test('UserService without a picture service keeps the wire shape backwards-compatible', function (): void {
    // Pre-feature UserService consumers (tests, plugins) construct
    // `new UserService()` with no args. The default-null picture
    // service means the wire shape carries `profile_picture: null`
    // instead of throwing.
    $service = new UserService();

    $result = $service->getUser($this->userId);

    expect($result)->not->toBeNull();
    expect($result['user']['profile_picture'])->toBeNull();
});
