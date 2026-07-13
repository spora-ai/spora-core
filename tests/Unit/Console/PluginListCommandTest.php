<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Spora\Console\Commands\PluginListCommand;
use Spora\Core\Extension\PluginManager;
use Spora\Core\Paths;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\FakeProcessFactory;

/**
 * Build a temp `plugins/` tree with the supplied slug → composer.json map
 * and return the base path. The caller is responsible for cleanup via
 * removePluginsTree().
 *
 * @param  array<string, array<string, mixed>>  $plugins  slug => composer.json body
 */
function buildPluginsTree(array $plugins): string
{
    $base = sys_get_temp_dir() . '/spora-plugin-list-cmd-' . uniqid();
    mkdir($base . '/plugins', 0o755, true);

    foreach ($plugins as $slug => $composerBody) {
        mkdir($base . '/plugins/' . $slug, 0o755, true);
        file_put_contents($base . '/plugins/' . $slug . '/plugin.json', json_encode(['slug' => $slug, 'class' => 'X\\' . $slug]));
        file_put_contents(
            $base . '/plugins/' . $slug . '/composer.json',
            json_encode($composerBody),
        );
    }

    // Resolve symlinks (macOS /tmp is a symlink to /private/tmp) so callers
    // can compare plugin paths verbatim against what list() returns.
    $resolved = realpath($base);
    return $resolved === false ? $base : $resolved;
}

function removePluginsTree(string $base): void
{
    if (!is_dir($base . '/plugins')) {
        @rmdir($base);
        return;
    }
    foreach (glob($base . '/plugins/*') ?: [] as $slugDir) {
        foreach (glob($slugDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($slugDir);
    }
    @rmdir($base . '/plugins');
    @rmdir($base);
}

function makePluginListTester(string $basePath): CommandTester
{
    // No FakeProcessFactory needed — PluginManager::list() no longer shells
    // out. We pass an empty factory for symmetry with the other command
    // tests; list() never invokes it.
    $manager = new PluginManager(
        new NullLogger(),
        Closure::fromCallable(new FakeProcessFactory()),
        new Paths($basePath),
    );

    $command = new PluginListCommand($manager);
    $command->setName('plugin:list');

    return new CommandTester($command);
}

it('prints an empty-state message when no plugins are installed', function (): void {
    $base = buildPluginsTree([]);

    try {
        $tester = makePluginListTester($base);
        $tester->execute([]);

        expect($tester->getStatusCode())->toBe(Command::SUCCESS);
        expect($tester->getDisplay())->toContain('No Spora plugins installed');
    } finally {
        removePluginsTree($base);
    }
});

it('renders a table with one row per installed plugin', function (): void {
    $base = buildPluginsTree([
        'tavily'     => ['name' => 'spora-ai/spora-plugin-tavily',     'version' => '0.1.0'],
        'semantics'  => ['name' => 'spora-ai/spora-plugin-semantic-scholar', 'version' => '0.2.0'],
        'minimax'    => ['name' => 'spora-ai/spora-plugin-minimax',     'version' => '0.5.0'],
    ]);

    try {
        $tester = makePluginListTester($base);
        $tester->execute([]);

        expect($tester->getStatusCode())->toBe(Command::SUCCESS);
        expect($tester->getDisplay())
            ->toContain('spora-ai/spora-plugin-tavily')
            ->toContain('0.1.0')
            ->toContain('spora-ai/spora-plugin-semantic-scholar')
            ->toContain('0.2.0')
            ->toContain('spora-ai/spora-plugin-minimax')
            ->toContain('0.5.0');
    } finally {
        removePluginsTree($base);
    }
});

it('renders (unknown) for plugins whose composer.json has no version', function (): void {
    $base = buildPluginsTree([
        'tavily' => ['name' => 'spora-ai/spora-plugin-tavily'], // no version key
    ]);

    try {
        $tester = makePluginListTester($base);
        $tester->execute([]);

        expect($tester->getStatusCode())->toBe(Command::SUCCESS);
        expect($tester->getDisplay())->toContain('(unknown)');
    } finally {
        removePluginsTree($base);
    }
});
