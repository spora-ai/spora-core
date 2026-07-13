<?php

declare(strict_types=1);

namespace Tests\Unit\Extension;

use Closure;
use Psr\Log\NullLogger;
use Spora\Core\Extension\Exceptions\PluginInstallFailedException;
use Spora\Core\Extension\PluginInstallRequest;
use Spora\Core\Extension\PluginInstallResult;
use Spora\Core\Extension\PluginManager;
use Spora\Core\Paths;
use Tests\Support\FakeProcessFactory;
use Tests\Support\InMemoryProcess;
use Tests\Support\PluginFixtures;

function makeManager(
    FakeProcessFactory $factory,
    string $basePath = '/srv/spora',
    string $composerBinary = 'composer',
): PluginManager {
    return new PluginManager(new NullLogger(), Closure::fromCallable($factory), new Paths($basePath), $composerBinary);
}

test('install() from registry builds a composer require argv with the bare package', function (): void {
    $factory = new FakeProcessFactory([
        'composer require spora-ai/spora-plugin-tavily --no-interaction --no-progress --optimize-autoloader' =>
            new InMemoryProcess([], '', "Installing spora-ai/spora-plugin-tavily\n"),
    ]);
    $manager = makeManager($factory);

    $result = $manager->install(new PluginInstallRequest('spora-ai/spora-plugin-tavily'));

    expect($result->status)->toBe(PluginInstallResult::STATUS_INSTALLED);
    expect($result->package)->toBe('spora-ai/spora-plugin-tavily');
    expect($result->constraint)->toBeNull();
    expect($result->message)->toContain('Installing spora-ai/spora-plugin-tavily');
    expect($factory->calls)->toHaveCount(1);
    expect($factory->calls[0]['cwd'])->toBe('/srv/spora');
});

test('install() from registry appends the version constraint when provided', function (): void {
    $factory = new FakeProcessFactory([
        'composer require spora-ai/spora-plugin-tavily:^1.0 --no-interaction --no-progress --optimize-autoloader' =>
            new InMemoryProcess([], ''),
    ]);
    $manager = makeManager($factory);

    $result = $manager->install(new PluginInstallRequest('spora-ai/spora-plugin-tavily', constraint: '^1.0'));

    expect($result->constraint)->toBe('^1.0');
    expect($factory->calls[0]['argv'][2])->toBe('spora-ai/spora-plugin-tavily:^1.0');
});

test('install() from path registers a path repo then requires the virtual package with *@dev', function (): void {
    $tmpPath = sys_get_temp_dir() . '/spora-fake-plugin-' . uniqid();
    mkdir($tmpPath);

    try {
        $factory = new FakeProcessFactory();
        $manager = makeManager($factory);

        $result = $manager->install(new PluginInstallRequest('spora-ai/spora-plugin-minimax', path: $tmpPath));

        expect($result->status)->toBe(PluginInstallResult::STATUS_INSTALLED);
        expect($result->path)->toBe($tmpPath);

        expect($factory->calls)->toHaveCount(2);
        expect($factory->calls[0]['argv'])->toBe([
            'composer', 'config', 'repositories.spora-ai.spora-plugin-minimax', 'path', $tmpPath,
        ]);
        expect($factory->calls[1]['argv'])->toBe([
            'composer', 'require', 'spora-ai/spora-plugin-minimax:*@dev',
            '--no-interaction', '--no-progress', '--optimize-autoloader',
        ]);
    } finally {
        rmdir($tmpPath);
    }
});

test('install() from a non-existent path throws without invoking composer', function (): void {
    $factory = new FakeProcessFactory();
    $manager = makeManager($factory);

    expect(fn() => $manager->install(new PluginInstallRequest('spora-ai/spora-plugin-x', path: '/nope/missing')))
        ->toThrow(PluginInstallFailedException::class, 'Path does not exist');

    expect($factory->calls)->toBe([]);
});

test('install() treats an empty-string path as a registry install', function (): void {
    $factory = new FakeProcessFactory();
    $manager = makeManager($factory);

    $manager->install(new PluginInstallRequest('spora-ai/spora-plugin-x', path: ''));

    expect($factory->calls)->toHaveCount(1);
    expect($factory->calls[0]['argv'][2])->toBe('spora-ai/spora-plugin-x');
});

test('install() from path uses the full vendor.name slug so acme/foo and spora-ai/foo do not collide', function (): void {
    $tmpPathA = sys_get_temp_dir() . '/spora-fake-plugin-a-' . uniqid();
    $tmpPathB = sys_get_temp_dir() . '/spora-fake-plugin-b-' . uniqid();
    mkdir($tmpPathA);
    mkdir($tmpPathB);

    try {
        $factory = new FakeProcessFactory();
        $manager = makeManager($factory);

        $manager->install(new PluginInstallRequest('acme/foo', path: $tmpPathA));
        $manager->install(new PluginInstallRequest('spora-ai/foo', path: $tmpPathB));

        expect($factory->calls[0]['argv'][2])->toBe('repositories.acme.foo');
        expect($factory->calls[2]['argv'][2])->toBe('repositories.spora-ai.foo');
    } finally {
        rmdir($tmpPathA);
        rmdir($tmpPathB);
    }
});

test('uninstall() invokes composer remove with the package', function (): void {
    $factory = new FakeProcessFactory([
        'composer remove spora-ai/spora-plugin-tavily --no-interaction --no-progress' =>
            new InMemoryProcess([], '', "Removing spora-ai/spora-plugin-tavily\n"),
    ]);
    $manager = makeManager($factory);

    $result = $manager->uninstall('spora-ai/spora-plugin-tavily');

    expect($result->status)->toBe(PluginInstallResult::STATUS_UNINSTALLED);
    expect($result->package)->toBe('spora-ai/spora-plugin-tavily');
    expect($factory->calls[0]['argv'])->toBe([
        'composer', 'remove', 'spora-ai/spora-plugin-tavily',
        '--no-interaction', '--no-progress',
    ]);
});

test('update() invokes composer update with the package and optimizer flag', function (): void {
    $factory = new FakeProcessFactory();
    $manager = makeManager($factory);

    $result = $manager->update('spora-ai/spora-plugin-tavily');

    expect($result->status)->toBe(PluginInstallResult::STATUS_UPDATED);
    expect($factory->calls[0]['argv'])->toBe([
        'composer', 'update', 'spora-ai/spora-plugin-tavily',
        '--no-interaction', '--no-progress', '--optimize-autoloader',
    ]);
});

test('update() without a package argument updates every installed plugin', function (): void {
    $factory = new FakeProcessFactory();
    $manager = makeManager($factory);

    $result = $manager->update();

    expect($result->status)->toBe(PluginInstallResult::STATUS_UPDATED);
    expect($factory->calls[0]['argv'])->toBe([
        'composer', 'update',
        '--no-interaction', '--no-progress', '--optimize-autoloader',
    ]);
});

test('list() scans the plugins directory for plugin.json manifests and reports name+version from sibling composer.json', function (): void {
    PluginFixtures::withTree([
        'tavily'    => ['name' => 'spora-ai/spora-plugin-tavily',           'version' => '0.1.0'],
        'semantics' => ['name' => 'spora-ai/spora-plugin-semantic-scholar', 'version' => '0.2.3'],
    ], function (string $base): void {
        $factory = new FakeProcessFactory();
        $manager = makeManager($factory, basePath: $base);

        // list() does no composer subprocess — the factory has no scripted responses,
        // and we explicitly assert no calls below.
        $entries = $manager->list();

        expect($factory->calls)->toBe([]);
        expect($entries)->toBe([
            ['name' => 'spora-ai/spora-plugin-semantic-scholar', 'version' => '0.2.3', 'path' => $base . '/plugins/semantics'],
            ['name' => 'spora-ai/spora-plugin-tavily',           'version' => '0.1.0', 'path' => $base . '/plugins/tavily'],
        ]);
    }, tag: 'spora-list-test');
});

test('list() ignores directories under plugins/ that do not ship a plugin.json', function (): void {
    // The glob filter is `plugins/*/plugin.json` — a directory without that
    // file (e.g. scratch space, future plugins not yet populated) must not
    // surface as a row. We can't express that shape with PluginFixtures
    // alone, so the scratch dir is added inline and cleaned up after.
    $base = PluginFixtures::buildTree([
        'tavily' => ['name' => 'spora-ai/spora-plugin-tavily', 'version' => '0.1.0'],
    ], tag: 'spora-list-scratch');
    mkdir($base . '/plugins/_scratch', 0o755, true);

    try {
        $factory = new FakeProcessFactory();
        $manager = makeManager($factory, basePath: $base);

        expect($manager->list())->toBe([
            ['name' => 'spora-ai/spora-plugin-tavily', 'version' => '0.1.0', 'path' => $base . '/plugins/tavily'],
        ]);
    } finally {
        @rmdir($base . '/plugins/_scratch');
        PluginFixtures::removeTree($base);
    }
});

test('list() returns [] when no plugin.json files exist in the plugins dir', function (): void {
    PluginFixtures::withTree([], function (string $base): void {
        $factory = new FakeProcessFactory();
        $manager = makeManager($factory, basePath: $base);

        expect($manager->list())->toBe([]);
        expect($factory->calls)->toBe([]); // confirm no composer subprocess
    }, tag: 'spora-list-empty');
});

test('list() returns [] when the plugins directory does not exist (fresh install)', function (): void {
    // No $base/plugins — fresh `composer install` never creates the dir.
    // Hand-built base; withTree() would create the dir for us.
    $base = sys_get_temp_dir() . '/spora-list-test-fresh-' . uniqid();

    $factory = new FakeProcessFactory();
    $manager = makeManager($factory, basePath: $base);

    expect($manager->list())->toBe([]);
    expect($factory->calls)->toBe([]);
});

test('list() surfaces plugins with missing or malformed composer.json (tolerant of partial installs)', function (): void {
    // PluginFixtures always writes composer.json; this corner case needs
    // a plugin.json-only sibling so the version-fallback path runs.
    $base = PluginFixtures::buildTree([], tag: 'spora-list-partial');
    mkdir($base . '/plugins/tavily', 0o755, true);
    file_put_contents(
        $base . '/plugins/tavily/plugin.json',
        json_encode(['slug' => 'tavily', 'class' => 'X'], JSON_THROW_ON_ERROR),
    );

    try {
        $factory = new FakeProcessFactory();
        $manager = makeManager($factory, basePath: $base);

        expect($manager->list())->toBe([
            ['name' => 'tavily', 'version' => null, 'path' => $base . '/plugins/tavily'],
        ]);
    } finally {
        @unlink($base . '/plugins/tavily/plugin.json');
        @rmdir($base . '/plugins/tavily');
        PluginFixtures::removeTree($base);
    }
});

test('failed composer process translates to PluginInstallFailedException with stderr and exit code', function (): void {
    $factory = new FakeProcessFactory([
        'composer require not-a-real-package --no-interaction --no-progress --optimize-autoloader' =>
            new InMemoryProcess([], '', '', 'Could not find package not-a-real-package', 2),
    ]);
    $manager = makeManager($factory);

    try {
        $manager->install(new PluginInstallRequest('not-a-real-package'));
        $this->fail('Expected PluginInstallFailedException');
    } catch (PluginInstallFailedException $e) {
        expect($e->exitCode)->toBe(2);
        expect($e->stderr)->toBe('Could not find package not-a-real-package');
        expect($e->getMessage())->toContain('composer exited 2');
        expect($e->getMessage())->toContain('Could not find package not-a-real-package');
    }
});

test('argv is passed as an array (no shell string is ever interpolated)', function (): void {
    // A package name containing shell metacharacters must reach the closure
    // as a literal argv element, not as a parsed shell command.
    $factory = new FakeProcessFactory();
    $manager = makeManager($factory);

    $manager->install(new PluginInstallRequest('spora-ai/$(rm -rf /)'));

    expect($factory->calls[0]['argv'][2])->toBe('spora-ai/$(rm -rf /)');
});

test('cwd is always the configured base path, never the cwd of the caller', function (): void {
    $factory = new FakeProcessFactory();
    $manager = makeManager($factory, basePath: '/var/www/spora');

    $manager->update('spora-ai/spora-plugin-tavily');
    $manager->uninstall('spora-ai/spora-plugin-tavily');

    foreach ($factory->calls as $call) {
        expect($call['cwd'])->toBe('/var/www/spora');
    }
});

test('composer binary defaults to "composer" on $PATH (no PHP_BINARY prefix)', function (): void {
    $factory = new FakeProcessFactory();
    $manager = makeManager($factory);

    $manager->install(new PluginInstallRequest('spora-ai/spora-plugin-x'));

    expect($factory->calls[0]['argv'][0])->toBe('composer');
    expect($factory->calls[0]['argv'][1])->toBe('require');
});

test('a custom composer binary path is used verbatim (e.g. /usr/local/bin/composer)', function (): void {
    $factory = new FakeProcessFactory();
    $manager = makeManager($factory, composerBinary: '/usr/local/bin/composer');

    $manager->install(new PluginInstallRequest('spora-ai/spora-plugin-x'));

    expect($factory->calls[0]['argv'][0])->toBe('/usr/local/bin/composer');
    expect($factory->calls[0]['argv'][1])->toBe('require');
});

test('a .phar composer binary is prefixed with PHP_BINARY so it executes via the runtime', function (): void {
    $factory = new FakeProcessFactory();
    $manager = makeManager($factory, composerBinary: '/srv/spora/bin/composer.phar');

    $manager->install(new PluginInstallRequest('spora-ai/spora-plugin-x'));

    expect($factory->calls[0]['argv'])->toBe([
        PHP_BINARY,
        '/srv/spora/bin/composer.phar',
        'require',
        'spora-ai/spora-plugin-x',
        '--no-interaction',
        '--no-progress',
        '--optimize-autoloader',
    ]);
});

test('phar prefix applies to every composer-spawning operation (install, uninstall, update)', function (): void {
    // list() no longer shells out to `composer show` — it walks the
    // filesystem directly — so the phar prefix only applies to the
    // three operations that still spawn a Composer subprocess.
    $factory = new FakeProcessFactory();
    $manager = makeManager($factory, composerBinary: 'composer.phar');

    $manager->install(new PluginInstallRequest('spora-ai/spora-plugin-x'));
    $manager->uninstall('spora-ai/spora-plugin-x');
    $manager->update('spora-ai/spora-plugin-x');

    expect($factory->calls)->toHaveCount(3);
    foreach ($factory->calls as $call) {
        expect($call['argv'][0])->toBe(PHP_BINARY);
        expect($call['argv'][1])->toBe('composer.phar');
    }
});
