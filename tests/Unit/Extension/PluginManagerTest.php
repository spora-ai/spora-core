<?php

declare(strict_types=1);

namespace Tests\Unit\Extension;

use Closure;
use Psr\Log\NullLogger;
use Spora\Core\Extension\Exceptions\PluginInstallFailedException;
use Spora\Core\Extension\PluginInstallRequest;
use Spora\Core\Extension\PluginInstallResult;
use Spora\Core\Extension\PluginManager;
use Tests\Support\FakeProcessFactory;
use Tests\Support\InMemoryProcess;

function makeManager(FakeProcessFactory $factory, string $basePath = '/srv/spora'): PluginManager
{
    return new PluginManager(new NullLogger(), Closure::fromCallable($factory), $basePath);
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
    expect($result->version)->toBeNull();
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

    $result = $manager->install(new PluginInstallRequest('spora-ai/spora-plugin-tavily', '^1.0'));

    expect($result->version)->toBe('^1.0');
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
            'composer', 'config', 'repositories.spora-plugin-minimax', 'path', $tmpPath,
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

test('list() filters composer show output to packages with type: spora-plugin', function (): void {
    $json = json_encode([
        ['name' => 'spora-ai/spora-plugin-tavily',     'version' => '0.1.0', 'type' => 'spora-plugin', 'path' => '/srv/spora/plugins/tavily'],
        ['name' => 'symfony/console',                  'version' => '8.0.0', 'type' => 'library'],
        ['name' => 'spora-ai/spora-plugin-semantics',  'version' => '0.2.0', 'type' => 'spora-plugin', 'path' => '/srv/spora/plugins/semantics'],
    ]);
    $factory = new FakeProcessFactory([
        'composer show --installed --direct --format=json' => new InMemoryProcess([], '', $json),
    ]);
    $manager = makeManager($factory);

    expect($manager->list())->toBe([
        ['name' => 'spora-ai/spora-plugin-tavily',    'version' => '0.1.0', 'path' => '/srv/spora/plugins/tavily'],
        ['name' => 'spora-ai/spora-plugin-semantics', 'version' => '0.2.0', 'path' => '/srv/spora/plugins/semantics'],
    ]);
});

test('list() returns [] when composer show emits undecodable JSON', function (): void {
    $factory = new FakeProcessFactory([
        'composer show --installed --direct --format=json' => new InMemoryProcess([], '', 'not json'),
    ]);
    $manager = makeManager($factory);

    expect($manager->list())->toBe([]);
});

test('list() returns [] when composer show exits non-zero', function (): void {
    $factory = new FakeProcessFactory([
        'composer show --installed --direct --format=json' =>
            new InMemoryProcess([], '', '', 'No composer.json found', 1),
    ]);
    $manager = makeManager($factory);

    expect($manager->list())->toBe([]);
});

test('list() returns [] when composer show emits empty output', function (): void {
    $factory = new FakeProcessFactory([
        'composer show --installed --direct --format=json' => new InMemoryProcess([], '', ''),
    ]);
    $manager = makeManager($factory);

    expect($manager->list())->toBe([]);
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
