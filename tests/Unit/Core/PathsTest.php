<?php

declare(strict_types=1);

use Spora\Core\Paths;

beforeEach(function (): void {
    // Tests use the operator-install layout (root as second-segment parent of phpunit's tests/Pest.php is the framework root;
    // the BASE_PATH constant defined in tests/Pest.php is the framework root, which is the "consumer root" for these tests).
});

it('base() returns the constructor base path', function (): void {
    $paths = new Paths('/srv/spora');
    expect($paths->base())->toBe('/srv/spora');
});

it('base(sub) appends a sub-path with leading/trailing slashes normalized', function (): void {
    $paths = new Paths('/srv/spora');
    expect($paths->base('storage'))->toBe('/srv/spora/storage')
        ->and($paths->base('/storage'))->toBe('/srv/spora/storage')
        ->and($paths->base('storage/'))->toBe('/srv/spora/storage')
        ->and($paths->base('a/b/c'))->toBe('/srv/spora/a/b/c');
});

it('storage() defaults to <base>/storage', function (): void {
    $paths = new Paths('/srv/spora');
    expect($paths->storage())->toBe('/srv/spora/storage')
        ->and($paths->storage('database.sqlite'))->toBe('/srv/spora/storage/database.sqlite');
});

it('storage() honours the SPORA_STORAGE_DIR env override', function (): void {
    $_SERVER['SPORA_STORAGE_DIR'] = '/var/lib/spora';
    try {
        $paths = new Paths('/srv/spora');
        expect($paths->storage())->toBe('/var/lib/spora')
            ->and($paths->storage('foo'))->toBe('/var/lib/spora/foo');
    } finally {
        unset($_SERVER['SPORA_STORAGE_DIR']);
    }
});

it('plugins() honours the SPORA_PLUGINS_DIR env override', function (): void {
    $_SERVER['SPORA_PLUGINS_DIR'] = '/srv/spora-plugins';
    try {
        $paths = new Paths('/srv/spora');
        expect($paths->plugins())->toBe('/srv/spora-plugins');
    } finally {
        unset($_SERVER['SPORA_PLUGINS_DIR']);
    }
});

it('config() and env() honour their respective env overrides', function (): void {
    $_SERVER['SPORA_CONFIG_FILE'] = '/etc/spora/config.php';
    $_SERVER['SPORA_ENV_FILE']    = '/etc/spora/.env';
    try {
        $paths = new Paths('/srv/spora');
        expect($paths->config())->toBe('/etc/spora/config.php')
            ->and($paths->env())->toBe('/etc/spora/.env');
    } finally {
        unset($_SERVER['SPORA_CONFIG_FILE'], $_SERVER['SPORA_ENV_FILE']);
    }
});

it('database() and recipes() default under <base>', function (): void {
    $paths = new Paths('/srv/spora');
    expect($paths->database('migrations'))->toBe('/srv/spora/database/migrations')
        ->and($paths->recipes())->toBe('/srv/spora/recipes');
});

it('framework() resolves to the framework install via reflection on Spora\\Core\\Kernel', function (): void {
    $paths = new Paths('/srv/spora');
    $framework = $paths->framework();

    // The framework is at <repo>/app/Core/Kernel.php — vendor or in-repo.
    // We can't pin to a specific path, but we can pin to: it must be an
    // existing directory, and framework('database/migrations') must exist.
    expect(is_dir($framework))->toBeTrue();
    expect(is_dir($paths->framework('database/migrations')))->toBeTrue();
});

it('framework() honours the second constructor arg when provided', function (): void {
    $paths = new Paths('/srv/spora', '/custom/framework');
    expect($paths->framework())->toBe('/custom/framework')
        ->and($paths->framework('database/migrations'))->toBe('/custom/framework/database/migrations');
});

it('emailTemplatesPaths() returns the project dir first (if it exists), then the framework dir', function (): void {
    $paths = new Paths(dirname(__DIR__, 3)); // tests/Unit/Core → tests/Unit → tests → repo root
    $list = $paths->emailTemplatesPaths();

    expect(count($list))->toBeGreaterThanOrEqual(1);
    // First entry: project override (if exists, else skipped) — either way, last entry is always the framework path
    expect(end($list))->toBe($paths->framework('email-templates'));
});

it('app() defaults to <base>/app', function (): void {
    $paths = new Paths('/srv/spora');
    expect($paths->app())->toBe('/srv/spora/app')
        ->and($paths->app('App.php'))->toBe('/srv/spora/app/App.php');
});

it('app() honours the SPORA_APP_DIR env override', function (): void {
    $_SERVER['SPORA_APP_DIR'] = '/var/spora/project-app';
    try {
        $paths = new Paths('/srv/spora');
        expect($paths->app())->toBe('/var/spora/project-app')
            ->and($paths->app('App.php'))->toBe('/var/spora/project-app/App.php');
    } finally {
        unset($_SERVER['SPORA_APP_DIR']);
    }
});

it('app() falls back to getenv() when $_SERVER is not populated', function (): void {
    // Mirror storage()/plugins()/recipes() pattern: env vars set at the process
    // level (where variables_order excludes 'E') must still be honoured.
    unset($_SERVER['SPORA_APP_DIR'], $_ENV['SPORA_APP_DIR']);
    putenv('SPORA_APP_DIR=/opt/spora-app');

    try {
        $paths = new Paths('/srv/spora');
        expect($paths->app())->toBe('/opt/spora-app');
    } finally {
        putenv('SPORA_APP_DIR');
    }
});
