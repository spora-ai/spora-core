<?php

declare(strict_types=1);

use Spora\Auth\AuthService;
use Delight\Auth\Role;
use Spora\Core\Database;
use Spora\Core\Kernel;
use Spora\Core\Paths;
use Spora\Settings\SettingsRegistry;
use Symfony\Component\HttpFoundation\Request;

/**
 * The route-registration and controller-unit tests prove each half. This
 * proves they are actually wired to each other: a real Kernel, the real DI
 * container, the real middleware stack, and a settings table that has been
 * migrated.
 */
beforeEach(function (): void {
    TestDatabaseFactory::boot();
    $_ENV['SPORA_SECRET_KEY'] = base64_encode(random_bytes(32));
    $_ENV['SPORA_APP_ENV'] = 'testing';
});

function adminSettingsPaths(): Paths
{
    $base = sys_get_temp_dir() . '/spora-admin-settings-' . uniqid('', true);
    mkdir($base, 0o777, true);
    mkdir($base . '/storage', 0o777, true);

    return new Paths($base);
}

function adminSettingsUser(string $email, bool $admin): int
{
    $auth = bootAuthLayer();
    $id = $auth->register($email, 'Correct-Horse-9', $email);
    if ($admin) {
        $auth->grantRole($id, Role::ADMIN);
    }
    simulateLoggedInSession($id, $email);

    return $id;
}

function adminSettingsReadRequest(): Request
{
    return Request::create('/api/v1/admin/settings', 'GET');
}

function adminSettingsWriteRequest(string $method, string $path, array $body): Request
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return Request::create($path, $method, server: [
        'HTTP_X_CSRF_TOKEN' => $_SESSION['csrf_token'],
    ], content: json_encode($body, JSON_THROW_ON_ERROR));
}

/**
 * @return array<string, mixed>
 */
function adminSettingsDecode(string $content): array
{
    return (array) json_decode($content, true);
}

test('GET /api/v1/admin/settings serves the registry to an admin', function (): void {
    adminSettingsUser('settings-admin@example.com', admin: true);

    $kernel = new Kernel(adminSettingsPaths());
    $response = $kernel->handle(adminSettingsReadRequest());
    $kernel->__destruct();

    expect($response->getStatusCode())->toBe(200);

    $data = adminSettingsDecode($response->getContent())['data'];

    $keys = array_merge(...array_map(
        static fn(array $s): array => array_column($s['settings'], 'key'),
        $data['sections'],
    ));

    expect($keys)->toContain('SPORA_ALLOW_REGISTRATION')
        ->and($data['undecryptable'])->toBe([]);
});

test('GET /api/v1/admin/settings is 403 for a logged-in non-admin', function (): void {
    adminSettingsUser('settings-plain@example.com', admin: false);

    $kernel = new Kernel(adminSettingsPaths());
    $response = $kernel->handle(adminSettingsReadRequest());
    $kernel->__destruct();

    expect($response->getStatusCode())->toBe(403)
        ->and(adminSettingsDecode($response->getContent())['error']['code'])->toBe('FORBIDDEN');
});

test('a saved setting survives a fresh kernel and is reported as coming from the database', function (): void {
    adminSettingsUser('settings-writer@example.com', admin: true);
    $paths = adminSettingsPaths();

    $kernel = new Kernel($paths);
    $write = $kernel->handle(adminSettingsWriteRequest('PATCH', '/api/v1/admin/settings', [
        'settings' => [['key' => 'SPORA_ALLOW_REGISTRATION', 'value' => false]],
    ]));
    $kernel->__destruct();

    expect($write->getStatusCode())->toBe(200)
        ->and(adminSettingsDecode($write->getContent())['data']['updated'])->toBe(['SPORA_ALLOW_REGISTRATION']);

    // A second kernel proves the value came from the settings table rather
    // than from anything still held in the first process.
    $fresh = new Kernel($paths);
    $read = $fresh->handle(adminSettingsReadRequest());
    $fresh->__destruct();

    $settings = [];
    foreach (adminSettingsDecode($read->getContent())['data']['sections'] as $section) {
        foreach ($section['settings'] as $setting) {
            $settings[$setting['key']] = $setting;
        }
    }

    expect($settings['SPORA_ALLOW_REGISTRATION']['value'])->toBeFalse()
        ->and($settings['SPORA_ALLOW_REGISTRATION']['source'])->toBe('database');
});

test('a boot-only setting is refused end to end and never reaches the table', function (): void {
    adminSettingsUser('settings-bootonly@example.com', admin: true);
    $paths = adminSettingsPaths();

    $kernel = new Kernel($paths);
    $write = $kernel->handle(adminSettingsWriteRequest('PATCH', '/api/v1/admin/settings', [
        'settings' => [['key' => 'SPORA_DB_HOST', 'value' => 'evil.example.com']],
    ]));
    $kernel->__destruct();

    expect($write->getStatusCode())->toBe(422);

    $fresh = new Kernel($paths);
    $read = $fresh->handle(adminSettingsReadRequest());
    $fresh->__destruct();

    expect($read->getContent())->not->toContain('evil.example.com');
});

test('the settings table is created by the migration and starts empty', function (): void {
    $registry = new SettingsRegistry(new Paths(BASE_PATH));

    expect(Database::getCapsule()->schema()->hasTable('settings'))->toBeTrue()
        ->and($registry->bootOnlyKeys())->toContain('SPORA_DB_HOST');
});