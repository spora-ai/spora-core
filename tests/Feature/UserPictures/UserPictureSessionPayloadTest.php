<?php

declare(strict_types=1);

namespace Tests\Feature\UserPictures;

use Spora\Core\Config;
use Spora\Core\Paths;
use Spora\Http\AuthController;
use Spora\Security\CsrfTokenService;
use Spora\Services\AuthValidator;
use Spora\Services\AuthWorkflow;
use Spora\Services\PrincipalLocationService;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Services\UserPictures\UserPictureService;
use Spora\Services\UserService;

const USER_PICTURE_SESSION_EMAIL = 'pic@example.com';
const USER_PICTURE_SESSION_PASSWORD = 'Password1!';

// 1x1 valid PNG (smallest possible image — 67 bytes)
const USER_PICTURE_SESSION_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

/**
 * The picture has to reach the SPA in every payload it hydrates its
 * session from — the login response and the session restore alike.
 */
beforeEach(function (): void {
    $this->storage = sys_get_temp_dir() . '/spora-user-picture-session-' . bin2hex(random_bytes(4));
    mkdir($this->storage . '/user-pictures', 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$this->storage}");
    $_ENV['SPORA_STORAGE_DIR']    = $this->storage;
    $_SERVER['SPORA_STORAGE_DIR'] = $this->storage;

    $this->auth = bootAuthLayer();
    $this->userId = $this->auth->register(
        USER_PICTURE_SESSION_EMAIL,
        USER_PICTURE_SESSION_PASSWORD,
        'Pic',
    );
    clearSession();

    $this->pictures = new UserPictureService(new Paths(BASE_PATH));
    $png = base64_decode(USER_PICTURE_SESSION_PNG_BASE64, true);
    $this->pictures->upload($this->userId, 'image/png', strlen((string) $png), (string) $png);

    $csrf = new CsrfTokenService();
    $validator = new AuthValidator();
    $workflow = new AuthWorkflow(
        $this->auth,
        new UserService(
            new PrincipalService(new PrincipalResolver()),
            $this->pictures,
            new PrincipalLocationService(),
        ),
        $csrf,
        $validator,
    );

    $this->controller = new AuthController(
        $this->auth,
        $csrf,
        $validator,
        $workflow,
        new Config(['SPORA_ALLOW_REGISTRATION' => true, 'SPORA_APP_ENV' => 'testing']),
    );
});

afterEach(function (): void {
    foreach (glob($this->storage . '/user-pictures/*') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($this->storage . '/user-pictures');
    @rmdir($this->storage);
});

it('carries the profile picture in the session payload', function (): void {
    simulateLoggedInSession($this->userId, USER_PICTURE_SESSION_EMAIL);

    $response = $this->controller->me(jsonRequest('GET', '/api/v1/auth/me'));

    expect($response->getStatusCode())->toBe(200);

    $body = json_decode((string) $response->getContent(), true);
    expect($body['data']['user']['profile_picture'])->not()->toBeNull();
    expect($body['data']['user']['profile_picture']['image_url'])
        ->toBe('/api/v1/users/' . $this->userId . '/picture');
});

it('carries the profile picture in the login payload', function (): void {
    clearSession();

    $response = $this->controller->login(jsonRequest('POST', '/api/v1/auth/login', [
        'email'    => USER_PICTURE_SESSION_EMAIL,
        'password' => USER_PICTURE_SESSION_PASSWORD,
    ]));

    expect($response->getStatusCode())->toBe(200);

    $body = json_decode((string) $response->getContent(), true);
    expect($body['data']['user']['profile_picture'])->not()->toBeNull();
    expect($body['data']['user']['profile_picture']['image_url'])
        ->toBe('/api/v1/users/' . $this->userId . '/picture');
});
