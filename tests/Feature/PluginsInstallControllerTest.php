<?php

declare(strict_types=1);

use Spora\Core\Extension\Exceptions\PluginInstallFailedException;
use Spora\Core\Extension\PluginInstallResult;
use Spora\Core\Extension\PluginManager;
use Spora\Http\Exceptions\FeatureDisabledException;
use Spora\Http\PluginsController;
use Spora\Plugins\PluginLoader;
use Spora\Services\PluginMetadataExtractor;
use Spora\Services\PluginsService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller-level tests for the Web UI plugin install surface
 * (POST / DELETE / PATCH). The middleware stack (Auth/Csrf/Admin) is covered
 * by other suites — see UserControllerTest for the same direct-call pattern.
 * Composer is faked via PluginManager's `processFactory` seam.
 */

function spora_makeInstallController(PluginManager $manager, bool $installEnabled): PluginsController
{
    $loader  = new PluginLoader([], null);
    $loader->boot();
    $service = new PluginsService($loader, new PluginMetadataExtractor());
    return new PluginsController($service, $manager, $installEnabled);
}

function spora_fakePluginManager(?Closure $capture = null): PluginManager
{
    $logger = new Monolog\Logger('test');

    $processFactory = static function (array $argv, string $cwd) use ($capture): object {
        if ($capture !== null) {
            $capture($argv, $cwd);
        }
        return new class {
            public function run(): void {}
            public function getExitCode(): int
            {
                return 0;
            }
            public function getOutput(): string
            {
                return 'Installing plugin (test fake)';
            }
            public function getErrorOutput(): string
            {
                return '';
            }
            public function isSuccessful(): bool
            {
                return true;
            }
        };
    };

    return new PluginManager(
        $logger,
        $processFactory,
        new Spora\Core\Paths(sys_get_temp_dir()),
        'composer',
    );
}

// POST /api/v1/plugins

test('store() throws FeatureDisabledException when flag is off', function (): void {
    $controller = spora_makeInstallController(spora_fakePluginManager(), false);
    $request = Request::create('/api/v1/plugins', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

    expect(fn() => $controller->store($request))->toThrow(FeatureDisabledException::class);
});

test('store() validates missing package field', function (): void {
    $controller = spora_makeInstallController(spora_fakePluginManager(), true);
    $body = json_encode(['constraint' => '^0.2']);
    $request = Request::create('/api/v1/plugins', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);

    $response = $controller->store($request);
    expect($response->getStatusCode())->toBe(400);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_FAILED');
    expect($body['error']['message'])->toContain('package');
});

test('store() rejects non-object JSON body', function (): void {
    $controller = spora_makeInstallController(spora_fakePluginManager(), true);
    // JSON array — valid JSON, wrong shape.
    $request = Request::create('/api/v1/plugins', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '[]');

    $response = $controller->store($request);
    expect($response->getStatusCode())->toBe(400);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_FAILED');
    expect($body['error']['message'])->toContain('JSON object');
});

test('store() validates malformed package shape', function (): void {
    $controller = spora_makeInstallController(spora_fakePluginManager(), true);
    $body = json_encode(['package' => 'not-a-vendor-name']);
    $request = Request::create('/api/v1/plugins', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);

    $response = $controller->store($request);
    expect($response->getStatusCode())->toBe(400);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_FAILED');
});

test('store() rejects when both constraint and path are set', function (): void {
    $controller = spora_makeInstallController(spora_fakePluginManager(), true);
    $body = json_encode([
        'package'    => 'spora-ai/spora-plugin-tavily',
        'constraint' => '^0.2',
        'path'       => '/tmp/some-path',
    ]);
    $request = Request::create('/api/v1/plugins', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);

    $response = $controller->store($request);
    expect($response->getStatusCode())->toBe(400);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_FAILED');
    expect($body['error']['message'])->toContain('either');
});

test('store() installs via the registry on a valid request', function (): void {
    $captured = null;
    $manager = spora_fakePluginManager(function (array $argv, string $cwd) use (&$captured): void {
        $captured = ['argv' => $argv, 'cwd' => $cwd];
    });
    $controller = spora_makeInstallController($manager, true);

    $body = json_encode(['package' => 'spora-ai/spora-plugin-tavily', 'constraint' => '^0.2']);
    $request = Request::create('/api/v1/plugins', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);

    $response = $controller->store($request);
    expect($response->getStatusCode())->toBe(200);

    $payload = json_decode($response->getContent(), true);
    expect($payload['data'])->toMatchArray([
        'package'    => 'spora-ai/spora-plugin-tavily',
        'status'     => PluginInstallResult::STATUS_INSTALLED,
        'constraint' => '^0.2',
    ]);

    // Confirm the captured argv actually invokes `composer require` with the right args.
    expect($captured['argv'])->toContain('require');
    expect($captured['argv'])->toContain('spora-ai/spora-plugin-tavily:^0.2');
});

test('store() surfaces PluginInstallFailedException for the Kernel to map', function (): void {
    // The controller lets the exception bubble; the Kernel-level test below
    // verifies the 500 mapping.
    $logger = new Monolog\Logger('test');
    $failingFactory = static function (array $argv, string $cwd): object {
        return new class {
            public function run(): void {}
            public function getExitCode(): int
            {
                return 2;
            }
            public function getOutput(): string
            {
                return '';
            }
            public function getErrorOutput(): string
            {
                return '  [InvalidArgumentException]  Could not find package.';
            }
            public function isSuccessful(): bool
            {
                return false;
            }
        };
    };
    $manager = new PluginManager(
        $logger,
        $failingFactory,
        new Spora\Core\Paths(sys_get_temp_dir()),
        'composer',
    );
    $controller = spora_makeInstallController($manager, true);

    $body = json_encode(['package' => 'spora-ai/spora-plugin-typo', 'constraint' => '^0.2']);
    $request = Request::create('/api/v1/plugins', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);

    expect(fn() => $controller->store($request))->toThrow(PluginInstallFailedException::class);
});

// DELETE /api/v1/plugins/{package}

test('destroy() throws FeatureDisabledException when flag is off', function (): void {
    $controller = spora_makeInstallController(spora_fakePluginManager(), false);

    expect(fn() => $controller->destroy('spora-ai/spora-plugin-tavily'))
        ->toThrow(FeatureDisabledException::class);
});

test('destroy() validates malformed package in the path', function (): void {
    $controller = spora_makeInstallController(spora_fakePluginManager(), true);

    $response = $controller->destroy('bogus');
    expect($response->getStatusCode())->toBe(400);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_FAILED');
});

test('destroy() uninstalls via the manager on a valid package', function (): void {
    $captured = null;
    $manager = spora_fakePluginManager(function (array $argv, string $cwd) use (&$captured): void {
        $captured = ['argv' => $argv, 'cwd' => $cwd];
    });
    $controller = spora_makeInstallController($manager, true);

    $response = $controller->destroy('spora-ai/spora-plugin-tavily');
    expect($response->getStatusCode())->toBe(200);

    $payload = json_decode($response->getContent(), true);
    expect($payload['data']['status'])->toBe(PluginInstallResult::STATUS_UNINSTALLED);

    expect($captured['argv'])->toContain('remove');
    expect($captured['argv'])->toContain('spora-ai/spora-plugin-tavily');
});

// PATCH /api/v1/plugins/{package}

test('update() throws FeatureDisabledException when flag is off', function (): void {
    $controller = spora_makeInstallController(spora_fakePluginManager(), false);
    $request = Request::create('/api/v1/plugins/spora-ai/spora-plugin-tavily', 'PATCH', [], [], [], ['CONTENT_TYPE' => 'application/json'], '');

    expect(fn() => $controller->update('spora-ai/spora-plugin-tavily', $request))
        ->toThrow(FeatureDisabledException::class);
});

test('update() with no constraint calls PluginManager::update()', function (): void {
    $captured = null;
    $manager = spora_fakePluginManager(function (array $argv, string $cwd) use (&$captured): void {
        $captured = ['argv' => $argv, 'cwd' => $cwd];
    });
    $controller = spora_makeInstallController($manager, true);

    $request = Request::create('/api/v1/plugins/spora-ai/spora-plugin-tavily', 'PATCH', [], [], [], ['CONTENT_TYPE' => 'application/json'], '');
    $response = $controller->update('spora-ai/spora-plugin-tavily', $request);

    expect($response->getStatusCode())->toBe(200);
    $payload = json_decode($response->getContent(), true);
    expect($payload['data']['status'])->toBe(PluginInstallResult::STATUS_UPDATED);

    // update() runs `composer update <pkg>` not `composer require <pkg>:<c>`.
    expect($captured['argv'])->toContain('update');
    expect($captured['argv'])->toContain('spora-ai/spora-plugin-tavily');
    expect($captured['argv'])->not->toContain('require');
});

test('update() with constraint re-pins via PluginManager::install()', function (): void {
    $captured = null;
    $manager = spora_fakePluginManager(function (array $argv, string $cwd) use (&$captured): void {
        $captured = ['argv' => $argv, 'cwd' => $cwd];
    });
    $controller = spora_makeInstallController($manager, true);

    $body = json_encode(['constraint' => '^0.3']);
    $request = Request::create('/api/v1/plugins/spora-ai/spora-plugin-tavily', 'PATCH', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
    $response = $controller->update('spora-ai/spora-plugin-tavily', $request);

    expect($response->getStatusCode())->toBe(200);
    expect($captured['argv'])->toContain('require');
    expect($captured['argv'])->toContain('spora-ai/spora-plugin-tavily:^0.3');
});

// PluginInstallFailedException → 500 mapping (Kernel)

test('PluginInstallFailedException maps to PLUGIN_INSTALL_FAILED via Kernel', function (): void {
    $exception = new PluginInstallFailedException(
        'composer require failed',
        exitCode: 2,
        stderr: 'Could not find package spora-ai/spora-plugin-typo.',
    );
    $response = Spora\Core\Kernel::mapPluginInstallFailureToResponse($exception);

    expect($response->getStatusCode())->toBe(500);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('PLUGIN_INSTALL_FAILED');
    expect($body['details']['exit_code'])->toBe(2);
    expect($body['details']['stderr'])->toContain('Could not find package');
});

test('PluginInstallFailedException truncates stderr at 8 KiB', function (): void {
    $bigStderr = str_repeat('A', 12_000);
    $exception = new PluginInstallFailedException(
        'composer failed',
        exitCode: 1,
        stderr: $bigStderr,
    );
    $response = Spora\Core\Kernel::mapPluginInstallFailureToResponse($exception);

    $body = json_decode($response->getContent(), true);
    expect(strlen($body['details']['stderr']))->toBeLessThan(strlen($bigStderr));
    expect($body['details']['stderr'])->toContain('[truncated; see storage/spora.log]');
});
